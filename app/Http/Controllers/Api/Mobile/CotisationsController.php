<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use App\Services\AirtelFeesCalculator;
use App\Services\OneSignalService;
use App\Services\OperateurDetectorService;
use App\Services\PaynalaPaymentService;
use App\Services\TondoConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cotisations entrantes (payin).
 *
 * Flux selon l'opérateur du payeur :
 *  – Airtel  → appel Paynala API, statut initial = `initie`,
 *              cagnotte créditée après confirmation via `GET /cotisations/{trans_id}/status`.
 *  – Autres  → mock immédiat (statut `succes`), à remplacer quand Moov etc. sont intégrés.
 *
 * Frais 2 % à la charge du cotisant (RÈGLE 4-bis).
 * request_id Paynala : alphanumérique uniquement (pas de tiret — contrainte API).
 */
class CotisationsController extends Controller
{
    public function __construct(
        private readonly PaynalaPaymentService    $paynala,
        private readonly OperateurDetectorService $detector,
    ) {}

    /** Commission Paynala lue depuis la config projet (jamais hardcodée). */
    private function commissionPaynala(string $projectId): float
    {
        $config = app(TondoConfigService::class)->getOperatorConfig($projectId);
        return (float) $config['commission_paynala'];
    }

    /**
     * POST /api/mobile/cotisations
     * Body : {
     *   cagnotte_reference: string,  // 4-5 chiffres
     *   montant: int,                // FCFA, montant net que touche la cagnotte
     *   indicatif_payeur?: string,   // override du numéro de l'user authentifié
     *   numero_payeur?: string,
     * }
     *
     * Réponse immédiate :
     *  – operateur Airtel : statut = `initie` → mobile doit poller /status
     *  – autres            : statut = `succes` (mock)
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cagnotte_reference' => ['required', 'string', 'regex:/^\d{4,5}$/'],
            'montant'            => ['required', 'integer', 'min:100', 'max:500000'],
            'indicatif_payeur'   => ['nullable', 'string', 'regex:/^\+?\d{1,4}$/'],
            'numero_payeur'      => ['nullable', 'string', 'regex:/^\d{6,12}$/'],
        ]);

        $user = $request->user();

        $cagnotte = TondoCagnotte::where('project_id', $user->project_id)
            ->where('reference', $data['cagnotte_reference'])
            ->first();

        if (! $cagnotte) {
            throw ValidationException::withMessages([
                'cagnotte_reference' => 'Cagnotte introuvable.',
            ]);
        }

        $statutsValides = ['active', 'en_cours'];
        if (! in_array($cagnotte->statut, $statutsValides)) {
            throw ValidationException::withMessages([
                'cagnotte_reference' => 'Cagnotte clôturée — cotisation impossible.',
            ]);
        }

        // Contrôle double cotisation (tontine uniquement) :
        // un participant ne peut cotiser qu'une fois par cycle.
        // statut_paiement = 'paye' indique que le cycle courant est déjà réglé.
        if ($cagnotte->type === 'tontine_periodique') {
            $dejaPayeCycle = DB::table('tondo_participants')
                ->where('cagnotte_id', $cagnotte->id)
                ->where('user_id', $user->id)
                ->where('statut_paiement', 'paye')
                ->exists();

            if ($dejaPayeCycle) {
                return response()->json([
                    'message'  => 'Vous avez déjà cotisé pour ce cycle.',
                    'statut'   => 'deja_paye',
                ], 422);
            }
        }

        // Numéro qui paie (E.164). Par défaut celui de l'user authentifié.
        $numeroPayeurE164 = $user->numero;
        if (! empty($data['indicatif_payeur']) && ! empty($data['numero_payeur'])) {
            $numeroPayeurE164 = '+' . ltrim($data['indicatif_payeur'], '+') . ltrim($data['numero_payeur'], '0');
        }

        $montantNet = $data['montant'];

        // Détecte l'opérateur depuis le numéro E.164 du payeur.
        $operateurInfo = $this->detector->detect($user->project_id, $numeroPayeurE164);
        $isAirtel      = $operateurInfo && $operateurInfo['operateur'] === 'airtel';

        // Modèle A : cotisant absorbe 2 % Paynala + frais de retrait Airtel.
        // Pour Airtel, on calcule le brut exact via AirtelFeesCalculator.
        // Pour les autres opérateurs (mock), on applique uniquement les 2 %.
        if ($isAirtel) {
            $airtelConfig = app(TondoConfigService::class)->getOperatorConfig($user->project_id);
            $calc         = new AirtelFeesCalculator($airtelConfig);
            $commission   = (float) $airtelConfig['commission_paynala'];
            $plan         = $calc->plan($montantNet);
            $montantBrut  = (int) ceil($plan['total_a_envoyer'] * (1 + $commission));
        } else {
            $commission  = $this->commissionPaynala($user->project_id);
            $montantBrut = (int) round($montantNet * (1 + $commission));
        }
        $frais = $montantBrut - $montantNet;

        if ($isAirtel) {
            return $this->storeAirtel(
                user: $user,
                cagnotte: $cagnotte,
                numeroPayeurE164: $numeroPayeurE164,
                operateurIndicatif: $operateurInfo['indicatif'],
                montantNet: $montantNet,
                frais: $frais,
                montantBrut: $montantBrut,
            );
        }

        return $this->storeMock(
            user: $user,
            cagnotte: $cagnotte,
            numeroPayeur: $numeroPayeurE164,
            montantNet: $montantNet,
            frais: $frais,
            montantBrut: $montantBrut,
        );
    }

    /**
     * GET /api/mobile/cotisations/{trans_id}/status
     *
     * Pour les paiements Airtel en cours (`statut = initie`), interroge l'API
     * Paynala et met à jour la DB si le statut a changé (SUCCESS → crédite la
     * cagnotte, FAILED → marque echec). Idempotent : si déjà `succes`/`echec`,
     * retourne l'état courant sans appel réseau.
     */
    public function status(Request $request, string $transId): JsonResponse
    {
        $user = $request->user();

        $payin = DB::table('tondo_payin')
            ->where('trans_id', $transId)
            ->where('project_id', $user->project_id)
            ->first();

        if (! $payin) {
            return response()->json(['message' => 'Transaction introuvable.'], 404);
        }

        // Déjà finalisé — pas d'appel réseau.
        if (in_array($payin->statut, ['succes', 'echec'])) {
            return response()->json([
                'trans_id' => $transId,
                'statut'   => $payin->statut,
            ]);
        }

        // Interroge l'API Paynala.
        try {
            $statusData = $this->paynala->checkStatus($transId);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $apiStatus = strtoupper($statusData['status'] ?? 'PENDING');

        if ($apiStatus === 'SUCCESS') {
            $requestMeta = json_decode($payin->request, true) ?? [];
            $commission  = $this->commissionPaynala($payin->project_id);
            $netAmount   = $requestMeta['montant_net'] ?? (int) round($payin->montant / (1 + $commission));

            try {
                DB::transaction(function () use ($payin, $statusData, $netAmount) {
                    // 1) Marque le payin comme réussi.
                    DB::table('tondo_payin')
                        ->where('trans_id', $payin->trans_id)
                        ->update([
                            'statut'     => 'succes',
                            'response'   => json_encode($statusData),
                            'updated_at' => now(),
                        ]);

                    // 2) Met à jour le participant.
                    $participant = DB::table('tondo_participants')
                        ->where('cagnotte_id', $payin->cagnotte_id)
                        ->where('user_id', $payin->user_id)
                        ->first();

                    if ($participant) {
                        DB::table('tondo_participants')
                            ->where('id', $participant->id)
                            ->update([
                                'statut_paiement'      => 'paye',
                                'montant_paye'         => DB::raw('montant_paye + ' . $netAmount),
                                'date_dernier_paiement' => now(),
                            ]);
                    }

                    // 3) Enregistre le paiement (audit fonctionnel).
                    DB::table('tondo_paiements')->insert([
                        'id'             => (string) Str::uuid(),
                        'project_id'     => $payin->project_id,
                        'cagnotte_id'    => $payin->cagnotte_id,
                        'participant_id' => $participant?->id,
                        'user_id'        => $payin->user_id,
                        'montant'        => $netAmount,
                        'date'           => now(),
                        'created_at'     => now(),
                    ]);

                    // 4) Crédite la cagnotte.
                    DB::table('tondo_cagnottes')
                        ->where('id', $payin->cagnotte_id)
                        ->update([
                            'montant_collecte' => DB::raw('montant_collecte + ' . $netAmount),
                            'updated_at'       => now(),
                        ]);
                });
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Erreur lors de la mise à jour du paiement.', 'error' => $e->getMessage()], 500);
            }

            $this->envoyerNotifsSucces(
                payeurId:    $payin->user_id,
                cagnotteId:  $payin->cagnotte_id,
                montantNet:  $netAmount,
            );

            return response()->json(['trans_id' => $transId, 'statut' => 'succes']);
        }

        if ($apiStatus === 'FAILED') {
            DB::table('tondo_payin')
                ->where('trans_id', $transId)
                ->update([
                    'statut'     => 'echec',
                    'response'   => json_encode($statusData),
                    'updated_at' => now(),
                ]);

            $message = $statusData['message'] ?? 'Paiement refusé ou expiré.';

            return response()->json([
                'trans_id' => $transId,
                'statut'   => 'echec',
                'message'  => $message,
            ]);
        }

        // Toujours PENDING.
        return response()->json(['trans_id' => $transId, 'statut' => 'initie']);
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Flux Airtel : appel Paynala → participant créé en `en_attente`, cagnotte
     * non encore créditée. La confirmation arrive via `status()`.
     */
    private function storeAirtel(
        mixed  $user,
        mixed  $cagnotte,
        string $numeroPayeurE164,
        string $operateurIndicatif,
        int    $montantNet,
        int    $frais,
        int    $montantBrut,
    ): JsonResponse {
        // request_id alphanumérique uniquement (contrainte API Paynala — pas de tirets).
        $transId = 'TONDOPAYIN' . strtoupper(Str::random(10));

        // Numéro local Airtel (9 chiffres : 074XXXXXX).
        $phoneE164      = ltrim($numeroPayeurE164, '+');
        $localSansZero  = substr($phoneE164, strlen($operateurIndicatif));
        $phoneAirtel    = '0' . $localSansZero;

        // Appel Paynala (bloquant — initie la notification sur le téléphone du client).
        try {
            $paymentData = $this->paynala->createPayment(
                requestId: $transId,
                amount:    $montantBrut,
                phone:     $phoneAirtel,
                firstName: $user->prenom ?? '',
                lastName:  $user->nom    ?? '',
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        try {
            DB::transaction(function () use (
                $user, $cagnotte, $numeroPayeurE164,
                $transId, $montantNet, $montantBrut, $frais, $phoneAirtel, $paymentData
            ) {
                // 1) Participant (placeholder en_attente).
                $participant = DB::table('tondo_participants')
                    ->where('cagnotte_id', $cagnotte->id)
                    ->where('user_id', $user->id)
                    ->first();

                $isNew = ! $participant;

                if ($isNew) {
                    $participantId = (string) Str::uuid();
                    DB::table('tondo_participants')->insert([
                        'id'                   => $participantId,
                        'project_id'           => $cagnotte->project_id,
                        'cagnotte_id'          => $cagnotte->id,
                        'user_id'              => $user->id,
                        'nom'                  => $user->nom,
                        'prenom'               => $user->prenom,
                        'numero_masque'        => $this->maskPhone($user->numero),
                        'statut_paiement'      => 'en_attente',
                        'montant_paye'         => 0,
                        'date_dernier_paiement' => null,
                        'created_at'           => now(),
                    ]);

                    $incrParticipants = $cagnotte->type === 'cagnotte_ouverte'
                        ? ['nombre_participants' => DB::raw('nombre_participants + 1')]
                        : [];
                    DB::table('tondo_cagnottes')
                        ->where('id', $cagnotte->id)
                        ->update(array_merge(
                            $incrParticipants,
                            ['nombre_inscrits' => DB::raw('nombre_inscrits + 1')],
                        ));
                }
                // Participant déjà inscrit : on ne change pas son statut_paiement
                // (il peut être 'paye' d'un cycle précédent). La mise à jour se fera
                // dans status() à la confirmation Airtel.

                // 2) Trace payin (statut initie — cagnotte pas encore créditée).
                DB::table('tondo_payin')->insert([
                    'id'            => (string) Str::uuid(),
                    'project_id'    => $cagnotte->project_id,
                    'cagnotte_id'   => $cagnotte->id,
                    'user_id'       => $user->id,
                    'trans_id'      => $transId,
                    'operateur_id'  => $paymentData['paymentId'] ?? null,
                    'numero_tel'    => $numeroPayeurE164,
                    'montant'       => $montantBrut,
                    'statut'        => 'initie',
                    'request'       => json_encode([
                        'request_id' => $transId,
                        'amount'     => $montantBrut,
                        'montant_net' => $montantNet,
                        'phone'      => $phoneAirtel,
                        'is_new_participant' => $isNew,
                        'cagnotte_type'      => $cagnotte->type,
                    ]),
                    'response'      => json_encode($paymentData),
                    'date_creation' => now(),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Erreur lors de l\'enregistrement du paiement.', 'error' => $e->getMessage()], 500);
        }

        return response()->json([
            'trans_id'     => $transId,
            'statut'       => 'initie',
            'montant_net'  => $montantNet,
            'frais'        => $frais,
            'montant_brut' => $montantBrut,
            'message'      => 'Vérifiez votre téléphone et confirmez le paiement Airtel Money.',
        ], 201);
    }

    /**
     * Flux mock (opérateurs non encore intégrés) — paiement immédiatement réussi.
     */
    private function storeMock(
        mixed  $user,
        mixed  $cagnotte,
        string $numeroPayeur,
        int    $montantNet,
        int    $frais,
        int    $montantBrut,
    ): JsonResponse {
        $transId = 'TONDOPAYIN' . strtoupper(Str::random(10));

        try {
            DB::transaction(function () use (
                $user, $cagnotte, $numeroPayeur, $transId, $montantNet, $montantBrut
            ) {
                $participant = DB::table('tondo_participants')
                    ->where('cagnotte_id', $cagnotte->id)
                    ->where('user_id', $user->id)
                    ->first();

                if (! $participant) {
                    $participantId = (string) Str::uuid();
                    DB::table('tondo_participants')->insert([
                        'id'                   => $participantId,
                        'project_id'           => $cagnotte->project_id,
                        'cagnotte_id'          => $cagnotte->id,
                        'user_id'              => $user->id,
                        'nom'                  => $user->nom,
                        'prenom'               => $user->prenom,
                        'numero_masque'        => $this->maskPhone($user->numero),
                        'statut_paiement'      => 'paye',
                        'montant_paye'         => $montantNet,
                        'date_dernier_paiement' => now(),
                        'created_at'           => now(),
                    ]);
                    $incrParticipants = $cagnotte->type === 'cagnotte_ouverte'
                        ? ['nombre_participants' => DB::raw('nombre_participants + 1')]
                        : [];
                    DB::table('tondo_cagnottes')
                        ->where('id', $cagnotte->id)
                        ->update(array_merge(
                            $incrParticipants,
                            ['nombre_inscrits' => DB::raw('nombre_inscrits + 1')],
                        ));
                } else {
                    $participantId = $participant->id;
                    DB::table('tondo_participants')
                        ->where('id', $participantId)
                        ->update([
                            'statut_paiement'      => 'paye',
                            'montant_paye'         => DB::raw('montant_paye + ' . $montantNet),
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
                    'numero_tel'    => $numeroPayeur,
                    'montant'       => $montantBrut,
                    'statut'        => 'succes',
                    'request'       => json_encode(['note' => 'mock — agrégateur non intégré']),
                    'response'      => json_encode(['ok' => true, 'mocked' => true]),
                    'date_creation' => now(),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                DB::table('tondo_cagnottes')
                    ->where('id', $cagnotte->id)
                    ->update([
                        'montant_collecte' => DB::raw('montant_collecte + ' . $montantNet),
                        'updated_at'       => now(),
                    ]);
            });
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Cotisation échouée.',
                'error'   => $e->getMessage(),
            ], 500);
        }

        $this->envoyerNotifsSucces(
            payeurId:   $user->id,
            cagnotteId: $cagnotte->id,
            montantNet: $montantNet,
        );

        $cagnotte->refresh();

        return response()->json([
            'trans_id'     => $transId,
            'statut'       => 'succes',
            'montant_net'  => $montantNet,
            'frais'        => $frais,
            'montant_brut' => $montantBrut,
            'cagnotte'     => [
                'reference'           => $cagnotte->reference,
                'titre'               => $cagnotte->titre,
                'montant_collecte'    => (int) $cagnotte->montant_collecte,
                'nombre_participants' => $cagnotte->nombre_participants,
            ],
        ], 201);
    }

    /**
     * Envoie les notifications post-paiement réussi :
     *  1. Confirmation au payeur.
     *  2. Alerte "X a cotisé" au créateur de la cagnotte (si différent du payeur).
     */
    private function envoyerNotifsSucces(string $payeurId, string $cagnotteId, int $montantNet): void
    {
        $cagnotte = DB::table('tondo_cagnottes')->where('id', $cagnotteId)->first();
        if (! $cagnotte) {
            return;
        }

        $payeur = DB::table('users')->where('id', $payeurId)->first();
        $svc    = app(OneSignalService::class);

        // 1) Confirmation au payeur.
        $svc->notifyOne(
            userId:  $payeurId,
            titleFr: 'Paiement confirmé',
            bodyFr:  "Votre cotisation de {$montantNet} FCFA pour « {$cagnotte->titre} » a été enregistrée.",
            data:    ['type' => 'paiement_effectue', 'cagnotte_id' => $cagnotteId],
        );

        // 2) Alerte au gérant (seulement s'il n'est pas lui-même le payeur).
        if ($cagnotte->user_id !== $payeurId) {
            $prenomNom = $payeur ? trim(($payeur->prenom ?? '') . ' ' . ($payeur->nom ?? '')) : 'Un participant';
            $svc->notifyOne(
                userId:  $cagnotte->user_id,
                titleFr: 'Nouvelle cotisation reçue',
                bodyFr:  "{$prenomNom} a cotisé {$montantNet} FCFA sur « {$cagnotte->titre} ».",
                data:    ['type' => 'cotisation_recue', 'cagnotte_id' => $cagnotteId],
            );
        }
    }

    private function maskPhone(string $phone): string
    {
        $clean = preg_replace('/[^\d+]/', '', $phone);
        if (strlen($clean) < 6) {
            return $clean;
        }
        $prefix = substr($clean, 0, strlen($clean) - 6);
        $last2  = substr($clean, -2);

        return $prefix . str_repeat('*', strlen($clean) - strlen($prefix) - 2) . $last2;
    }
}
