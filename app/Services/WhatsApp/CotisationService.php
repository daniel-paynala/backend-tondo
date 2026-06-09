<?php

namespace App\Services\WhatsApp;

use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use App\Services\AirtelFeesCalculator;
use App\Services\OperateurDetectorService;
use App\Services\PaynalaPaymentService;
use App\Services\TondoConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Logique de paiement (payin) pour le canal WhatsApp.
 *
 * Miroir de CotisationsController sans la couche HTTP :
 * retourne des tableaux au lieu de JsonResponse.
 *
 * Résultat initier() :
 *   ['statut' => 'initie',  'trans_id' => '...']          → Airtel, push envoyé
 *   ['statut' => 'succes',  'trans_id' => '...', ...]     → Mock, crédité immédiatement
 *   ['statut' => 'erreur',  'message'  => '...']          → échec technique
 *
 * Résultat verifierStatut() :
 *   'initie' | 'succes' | 'echec'
 */
class CotisationService
{
    public function __construct(
        private readonly PaynalaPaymentService    $paynala,
        private readonly OperateurDetectorService $detector,
        private readonly TondoConfigService       $config,
    ) {}

    // ── Créer un compte light ────────────────────────────────────────────────

    public function creerCompteLight(
        string $nom,
        string $prenom,
        string $numeroE164,
        string $projectId,
    ): TondoUser {
        $existant = TondoUser::where('project_id', $projectId)
            ->where(function ($q) use ($numeroE164) {
                $suffixe = substr(preg_replace('/\D/', '', $numeroE164), -9);
                $q->where('telephone', 'like', "%{$suffixe}")
                  ->orWhere('numero', 'like', "%{$suffixe}");
            })
            ->first();

        if ($existant) {
            return $existant;
        }

        $user = new TondoUser();
        $user->id             = (string) Str::uuid();
        $user->project_id     = $projectId;
        $user->nom            = mb_strtoupper(trim($nom));
        $user->prenom         = ucfirst(mb_strtolower(trim($prenom)));
        $user->numero         = $numeroE164;
        $user->indicatif      = '+241';
        $user->pays           = 'GA';
        $user->operateur      = null;
        $user->date_naissance = '1900-01-01';   // placeholder — compte light WhatsApp
        $user->kyc_valide     = false;
        $user->type_client    = 'particulier';
        $user->created_at     = now();
        $user->updated_at     = now();
        $user->save();

        return $user;
    }

    // ── Initier un paiement ───────────────────────────────────────────────────

    /**
     * Initie le payin (Airtel push ou mock selon l'opérateur).
     * Ne nécessite pas de Request — opère directement sur les modèles.
     */
    public function initier(
        TondoUser    $user,
        TondoCagnotte $cagnotte,
        int           $montant,
    ): array {
        // Penalité (tontine uniquement)
        $penalite = 0;
        if ($cagnotte->type === 'tontine_periodique') {
            $cyclesCompletes = DB::table('tondo_payout')
                ->where('cagnotte_id', $cagnotte->id)
                ->where('statut', 'succes')
                ->count();
            try {
                $penalite = app(\App\Services\TontineService::class)
                    ->calculerPenalite($cagnotte, $cyclesCompletes);
            } catch (\Throwable) {
                $penalite = 0;
            }
        }

        // Détection opérateur
        $operateurInfo = $this->detector->detect($cagnotte->project_id, $user->numero);
        $isAirtel      = $operateurInfo && $operateurInfo['operateur'] === 'airtel';

        // Calcul des frais
        if ($isAirtel) {
            $airtelConfig = $this->config->getOperatorConfig($cagnotte->project_id);
            $calc         = new AirtelFeesCalculator($airtelConfig);
            $commission   = (float) $airtelConfig['commission_paynala'];
            $plan         = $calc->plan($montant);
            $montantBrut  = (int) ceil($plan['total_a_envoyer'] * (1 + $commission));
        } else {
            $configData  = $this->config->getOperatorConfig($cagnotte->project_id);
            $commission  = (float) ($configData['commission_paynala'] ?? 0.02);
            $montantBrut = (int) round($montant * (1 + $commission));
        }

        $frais        = $montantBrut - $montant;
        $montantTotal = $montantBrut + $penalite;

        if ($isAirtel) {
            return $this->initierAirtel(
                user: $user, cagnotte: $cagnotte,
                montantNet: $montant, frais: $frais,
                montantBrut: $montantTotal, penalite: $penalite,
                operateurIndicatif: $operateurInfo['indicatif'],
            );
        }

        return $this->initierMock(
            user: $user, cagnotte: $cagnotte,
            montantNet: $montant, frais: $frais,
            montantBrut: $montantTotal, penalite: $penalite,
        );
    }

    // ── Vérifier le statut d'une transaction ─────────────────────────────────

    /**
     * Interroge l'API Paynala et met à jour la DB si le statut a changé.
     * Retourne : 'initie' | 'succes' | 'echec'
     */
    public function verifierStatut(string $transId, string $projectId): string
    {
        $payin = DB::table('tondo_payin')
            ->where('trans_id', $transId)
            ->where('project_id', $projectId)
            ->first();

        if (! $payin) {
            return 'echec';
        }

        if (in_array($payin->statut, ['succes', 'echec'])) {
            return $payin->statut;
        }

        try {
            $statusData = $this->paynala->checkStatus($transId);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp CotisationService::verifierStatut erreur', ['err' => $e->getMessage()]);
            return 'initie';
        }

        $apiStatus = strtoupper($statusData['status'] ?? 'PENDING');

        if ($apiStatus === 'SUCCESS') {
            $this->crediterSurSucces($payin, $statusData);
            return 'succes';
        }

        if ($apiStatus === 'FAILED') {
            DB::table('tondo_payin')
                ->where('trans_id', $transId)
                ->update(['statut' => 'echec', 'response' => json_encode($statusData), 'updated_at' => now()]);
            return 'echec';
        }

        return 'initie';
    }

    // ── Privé — flux Airtel ───────────────────────────────────────────────────

    private function initierAirtel(
        TondoUser    $user,
        TondoCagnotte $cagnotte,
        int           $montantNet,
        int           $frais,
        int           $montantBrut,
        int           $penalite,
        string        $operateurIndicatif,
    ): array {
        $transId       = 'TONDOPAYIN' . strtoupper(Str::random(10));
        $phoneE164     = ltrim($user->numero, '+');
        $localSansZero = substr($phoneE164, strlen(ltrim($operateurIndicatif, '+')));
        $phoneAirtel   = '0' . $localSansZero;

        try {
            $paymentData = $this->paynala->createPayment(
                requestId: $transId,
                amount:    $montantBrut,
                phone:     $phoneAirtel,
                firstName: $user->prenom ?? '',
                lastName:  $user->nom    ?? '',
            );
        } catch (\RuntimeException $e) {
            return ['statut' => 'erreur', 'message' => $e->getMessage()];
        }

        try {
            DB::transaction(function () use (
                $user, $cagnotte, $transId,
                $montantNet, $montantBrut, $frais, $penalite, $paymentData, $phoneAirtel
            ) {
                $participant = DB::table('tondo_participants')
                    ->where('cagnotte_id', $cagnotte->id)
                    ->where('user_id', $user->id)
                    ->first();

                $isNew = ! $participant;

                if ($isNew) {
                    DB::table('tondo_participants')->insert([
                        'id'               => (string) Str::uuid(),
                        'project_id'       => $cagnotte->project_id,
                        'cagnotte_id'      => $cagnotte->id,
                        'user_id'          => $user->id,
                        'nom'              => $user->nom,
                        'prenom'           => $user->prenom,
                        'numero_masque'    => $this->maskPhone($user->numero),
                        'statut_paiement'  => 'en_attente',
                        'montant_paye'     => 0,
                        'created_at'       => now(),
                    ]);
                    if ($cagnotte->type === 'cagnotte_ouverte') {
                        DB::table('tondo_cagnottes')->where('id', $cagnotte->id)
                            ->increment('nombre_participants');
                    }
                    DB::table('tondo_cagnottes')->where('id', $cagnotte->id)
                        ->increment('nombre_inscrits');
                }

                DB::table('tondo_payin')->insert([
                    'id'               => (string) Str::uuid(),
                    'project_id'       => $cagnotte->project_id,
                    'cagnotte_id'      => $cagnotte->id,
                    'user_id'          => $user->id,
                    'trans_id'         => $transId,
                    'operateur_id'     => $paymentData['paymentId'] ?? null,
                    'numero_tel'       => $user->numero,
                    'montant'          => $montantBrut,
                    'montant_penalite' => $penalite,
                    'statut'           => 'initie',
                    'request'          => json_encode([
                        'request_id'  => $transId,
                        'amount'      => $montantBrut,
                        'montant_net' => $montantNet,
                        'phone'       => $phoneAirtel,
                        'canal'       => 'whatsapp',
                    ]),
                    'response'         => json_encode($paymentData),
                    'date_creation'    => now(),
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            });
        } catch (\Throwable $e) {
            return ['statut' => 'erreur', 'message' => 'Erreur enregistrement : ' . $e->getMessage()];
        }

        return [
            'statut'      => 'initie',
            'trans_id'    => $transId,
            'montant_net' => $montantNet,
            'frais'       => $frais,
            'montant_brut'=> $montantBrut,
        ];
    }

    // ── Privé — flux Mock ─────────────────────────────────────────────────────

    private function initierMock(
        TondoUser    $user,
        TondoCagnotte $cagnotte,
        int           $montantNet,
        int           $frais,
        int           $montantBrut,
        int           $penalite,
    ): array {
        $transId = 'TONDOPAYIN' . strtoupper(Str::random(10));

        try {
            DB::transaction(function () use (
                $user, $cagnotte, $transId, $montantNet, $montantBrut, $penalite
            ) {
                $participant = DB::table('tondo_participants')
                    ->where('cagnotte_id', $cagnotte->id)
                    ->where('user_id', $user->id)
                    ->first();

                $participantId = $participant?->id ?? (string) Str::uuid();

                if (! $participant) {
                    DB::table('tondo_participants')->insert([
                        'id'              => $participantId,
                        'project_id'      => $cagnotte->project_id,
                        'cagnotte_id'     => $cagnotte->id,
                        'user_id'         => $user->id,
                        'nom'             => $user->nom,
                        'prenom'          => $user->prenom,
                        'numero_masque'   => $this->maskPhone($user->numero),
                        'statut_paiement' => 'paye',
                        'montant_paye'    => $montantNet,
                        'date_dernier_paiement' => now(),
                        'created_at'      => now(),
                    ]);
                    if ($cagnotte->type === 'cagnotte_ouverte') {
                        DB::table('tondo_cagnottes')->where('id', $cagnotte->id)->increment('nombre_participants');
                    }
                    DB::table('tondo_cagnottes')->where('id', $cagnotte->id)->increment('nombre_inscrits');
                } else {
                    DB::table('tondo_participants')->where('id', $participantId)->update([
                        'statut_paiement'       => 'paye',
                        'montant_paye'          => DB::raw('montant_paye + ' . $montantNet),
                        'date_dernier_paiement' => now(),
                    ]);
                }

                DB::table('tondo_paiements')->insert([
                    'id'             => (string) Str::uuid(),
                    'project_id'     => $cagnotte->project_id,
                    'cagnotte_id'    => $cagnotte->id,
                    'participant_id' => $participantId,
                    'user_id'        => $user->id,
                    'montant'        => $montantNet,
                    'date'           => now(),
                    'created_at'     => now(),
                ]);

                DB::table('tondo_payin')->insert([
                    'id'            => (string) Str::uuid(),
                    'project_id'    => $cagnotte->project_id,
                    'cagnotte_id'   => $cagnotte->id,
                    'user_id'       => $user->id,
                    'trans_id'      => $transId,
                    'operateur_id'  => 'MOCK-' . substr($transId, -8),
                    'numero_tel'    => $user->numero,
                    'montant'          => $montantBrut,
                    'montant_penalite' => $penalite,
                    'statut'           => 'succes',
                    'request'          => json_encode(['note' => 'mock whatsapp', 'canal' => 'whatsapp']),
                    'response'         => json_encode(['ok' => true, 'mocked' => true]),
                    'date_creation'    => now(),
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);

                DB::table('tondo_cagnottes')->where('id', $cagnotte->id)
                    ->increment('montant_collecte', $montantNet);
            });
        } catch (\Throwable $e) {
            return ['statut' => 'erreur', 'message' => $e->getMessage()];
        }

        return [
            'statut'       => 'succes',
            'trans_id'     => $transId,
            'montant_net'  => $montantNet,
            'frais'        => $frais,
            'montant_brut' => $montantBrut,
        ];
    }

    // ── Créditer sur succès Airtel (appelé par verifierStatut) ───────────────

    private function crediterSurSucces(object $payin, array $statusData): void
    {
        $requestMeta = json_decode($payin->request, true) ?? [];
        $netAmount   = $requestMeta['montant_net'] ?? (int) round($payin->montant * 0.98);

        DB::transaction(function () use ($payin, $statusData, $netAmount) {
            DB::table('tondo_payin')->where('trans_id', $payin->trans_id)->update([
                'statut'     => 'succes',
                'response'   => json_encode($statusData),
                'updated_at' => now(),
            ]);

            $participant = DB::table('tondo_participants')
                ->where('cagnotte_id', $payin->cagnotte_id)
                ->where('user_id', $payin->user_id)
                ->first();

            if ($participant) {
                DB::table('tondo_participants')->where('id', $participant->id)->update([
                    'statut_paiement'       => 'paye',
                    'montant_paye'          => DB::raw('montant_paye + ' . $netAmount),
                    'date_dernier_paiement' => now(),
                ]);

                DB::table('tondo_paiements')->insert([
                    'id'             => (string) Str::uuid(),
                    'project_id'     => $payin->project_id,
                    'cagnotte_id'    => $payin->cagnotte_id,
                    'participant_id' => $participant->id,
                    'user_id'        => $payin->user_id,
                    'montant'        => $netAmount,
                    'date'           => now(),
                    'created_at'     => now(),
                ]);
            }

            DB::table('tondo_cagnottes')->where('id', $payin->cagnotte_id)
                ->increment('montant_collecte', $netAmount);
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function maskPhone(string $phone): string
    {
        $clean = preg_replace('/[^\d+]/', '', $phone);
        if (strlen($clean) < 6) return $clean;
        $prefix = substr($clean, 0, strlen($clean) - 6);
        return $prefix . str_repeat('*', strlen($clean) - strlen($prefix) - 2) . substr($clean, -2);
    }
}
