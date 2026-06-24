<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Mail\DisbursementFailedMail;
use App\Models\TondoCagnotte;
use App\Services\PaynalaPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reversements partiels (payout gérant → bénéficiaire).
 *
 * Disponible uniquement pour les cagnottes ouvertes, uniquement pour le
 * créateur.
 *
 * Contrôles de sécurité financière :
 *  – Solde vérifié sous row-lock (anti-race-condition).
 *  – Enregistrement `initie` AVANT l'appel Paynala → zéro transaction fantôme.
 *  – Échec Paynala → alerte email aux admins, intervention manuelle requise.
 */
class ReversementsController extends Controller
{
    public function __construct(
        private readonly PaynalaPaymentService $paynala,
    ) {}

    /**
     * POST /api/mobile/reversements
     * Body : {
     *   cagnotte_reference   : string  (4-5 chiffres)
     *   numero_beneficiaire  : string|null  (9 chiffres local, ex : 074577473)
     *   membre_id        : string|null  (UUID tondo_participants.id)
     *   montant              : int           (FCFA, min 100, max 500 000)
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cagnotte_reference'  => ['required', 'string', 'regex:/^\d{6}$/'],
            'numero_beneficiaire' => ['nullable', 'string', 'regex:/^\d{9}$/'],
            'participant_id'      => ['nullable', 'string', 'uuid'],
            'montant'             => ['required', 'integer', 'min:100'],
        ]);

        if (empty($data['numero_beneficiaire']) && empty($data['participant_id'])) {
            throw ValidationException::withMessages([
                'numero_beneficiaire' => 'Indiquez un numéro bénéficiaire ou sélectionnez un membre.',
            ]);
        }

        $user = $request->user();

        $cagnotte = TondoCagnotte::where('project_id', $user->project_id)
            ->where('reference', $data['cagnotte_reference'])
            ->first();

        if (! $cagnotte) {
            throw ValidationException::withMessages([
                'cagnotte_reference' => 'Cagnotte introuvable.',
            ]);
        }

        if ($cagnotte->user_id !== $user->id) {
            return response()->json([
                'message' => 'Seul le créateur peut effectuer un reversement.',
            ], 403);
        }

        if ($cagnotte->type !== 'cagnotte_ouverte') {
            return response()->json([
                'message' => 'Le reversement est disponible uniquement pour les cagnottes ouvertes.',
            ], 422);
        }

        if (! in_array($cagnotte->statut, ['active', 'en_cours'])) {
            throw ValidationException::withMessages([
                'cagnotte_reference' => 'Cagnotte clôturée — reversement impossible.',
            ]);
        }

        // ── Résolution du numéro bénéficiaire ────────────────────────────────
        $beneficiaireUserId = null;

        if (! empty($data['participant_id'])) {
            $participant = DB::table('tondo_participants')
                ->join('users', 'tondo_participants.user_id', '=', 'users.id')
                ->where('tondo_participants.id', $data['participant_id'])
                ->where('tondo_participants.cagnotte_id', $cagnotte->id)
                ->select('users.id as user_id_benef', 'users.numero as numero_user')
                ->first();

            if (! $participant || empty($participant->numero_user)) {
                throw ValidationException::withMessages([
                    'participant_id' => 'Membre introuvable dans cette cagnotte.',
                ]);
            }

            $numeroBeneficiaireE164 = $participant->numero_user;
            $beneficiaireUserId     = $participant->user_id_benef;
        } else {
            $numeroBeneficiaireE164 = '+241' . ltrim($data['numero_beneficiaire'], '0');
            // Cherche si ce numéro correspond à un compte Tondo.
            $benefUser          = DB::table('users')
                ->where('numero', $numeroBeneficiaireE164)
                ->value('id');
            $beneficiaireUserId = $benefUser ?? null;
        }

        // Numéro local 9 chiffres requis par l'API Paynala disburse.
        $msisdnLocal = str_starts_with($numeroBeneficiaireE164, '+241')
            ? '0' . substr($numeroBeneficiaireE164, 4)
            : $numeroBeneficiaireE164;

        // ── Génération des identifiants Paynala ──────────────────────────────
        $nextNum        = DB::table('tondo_payout')->count() + 1;
        $typeLabel      = $cagnotte->type === 'tontine_periodique' ? 'TONTINE' : 'COTISATION';
        $reference      = 'TONDODISBURSEMENT' . now()->getTimestampMs();
        $idempotencyKey = 'TONDO-' . $typeLabel . '-' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);

        $payoutId = (string) Str::uuid();
        $transId  = 'TONDOPAYOUT' . strtoupper(Str::random(9));

        // ── PHASE 1 : réserver les fonds sous row-lock ───────────────────────
        // On verrouille la ligne cagnotte, on re-vérifie le solde et on insère
        // le payout en statut 'initie' dans la même transaction. Cela empêche
        // deux requêtes simultanées de vider la même cagnotte (race condition).
        try {
            DB::transaction(function () use (
                $cagnotte, $data, $payoutId, $transId, $idempotencyKey,
                $reference, $numeroBeneficiaireE164, $beneficiaireUserId, $user
            ) {
                // Verrouillage exclusif de la ligne cagnotte.
                $soldeActuel = DB::table('tondo_cagnottes')
                    ->where('id', $cagnotte->id)
                    ->lockForUpdate()
                    ->value('montant_collecte');

                if ((int) $soldeActuel < $data['montant']) {
                    throw ValidationException::withMessages([
                        'montant' => 'Solde insuffisant. Disponible : '
                            . number_format((int) $soldeActuel, 0, ',', ' ') . ' FCFA.',
                    ]);
                }

                // Enregistrement initie — fonds "réservés" côté Tondo.
                DB::table('tondo_payout')->insert([
                    'id'            => $payoutId,
                    'project_id'    => $cagnotte->project_id,
                    'cagnotte_id'   => $cagnotte->id,
                    'user_id'       => $beneficiaireUserId,  // bénéficiaire, pas le gérant
                    'trans_id'      => $transId,
                    'operateur_id'  => null,
                    'numero_tel'    => $numeroBeneficiaireE164,
                    'montant'       => $data['montant'],
                    'statut'        => 'initie',
                    'request'       => json_encode([
                        'idempotency_key'     => $idempotencyKey,
                        'reference'           => $reference,
                        'cagnotte_reference'  => $cagnotte->reference,
                        'numero_beneficiaire' => $numeroBeneficiaireE164,
                        'montant'             => $data['montant'],
                    ]),
                    'date_creation' => now(),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                // Décrémentation atomique du solde.
                DB::table('tondo_cagnottes')
                    ->where('id', $cagnotte->id)
                    ->update([
                        'montant_collecte' => DB::raw('montant_collecte - ' . (int) $data['montant']),
                        'updated_at'       => now(),
                    ]);
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[reversement] échec phase réservation', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Erreur lors de la réservation des fonds.',
                'error'   => $e->getMessage(),
            ], 500);
        }

        // ── PHASE 2 : appel Paynala (hors transaction DB) ────────────────────
        $disburseType = $this->paynala->resolveDisburseType(
            msisdnLocal: $msisdnLocal,
            msisdnE164:  $numeroBeneficiaireE164,
            userId:      $beneficiaireUserId,
        );

        try {
            $disburseData = $this->paynala->disburse(
                idempotencyKey: $idempotencyKey,
                amount:         $data['montant'],
                msisdn:         $msisdnLocal,
                reference:      $reference,
                type:           $disburseType,
            );
        } catch (\RuntimeException $e) {
            // Paynala a échoué APRÈS la réservation des fonds.
            // On ne restaure PAS automatiquement le solde — l'état Paynala
            // est inconnu. On marque 'echec' et on alerte les admins pour
            // qu'ils vérifient manuellement avant toute correction.
            DB::table('tondo_payout')
                ->where('id', $payoutId)
                ->update([
                    'statut'     => 'echec',
                    'response'   => json_encode(['error' => $e->getMessage()]),
                    'updated_at' => now(),
                ]);

            Log::critical('[reversement] échec Paynala — INTERVENTION MANUELLE REQUISE', [
                'payout_id'       => $payoutId,
                'trans_id'        => $transId,
                'cagnotte_ref'    => $cagnotte->reference,
                'montant'         => $data['montant'],
                'beneficiaire'    => $numeroBeneficiaireE164,
                'idempotency_key' => $idempotencyKey,
                'error'           => $e->getMessage(),
            ]);

            // Alerte email à tous les admins actifs.
            try {
                $adminEmails = DB::table('tondo_admins')
                    ->where('actif', true)
                    ->pluck('email')
                    ->toArray();

                if (! empty($adminEmails)) {
                    Mail::to($adminEmails)->send(new DisbursementFailedMail(
                        payoutId:          $payoutId,
                        transId:           $transId,
                        cagnotteReference: $cagnotte->reference,
                        montant:           $data['montant'],
                        numeroBeneficiaire: $numeroBeneficiaireE164,
                        idempotencyKey:    $idempotencyKey,
                        errorMessage:      $e->getMessage(),
                    ));
                }
            } catch (\Throwable $mailEx) {
                Log::error('[reversement] impossible d\'envoyer l\'alerte email', [
                    'mail_error' => $mailEx->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Le transfert a échoué. Les administrateurs ont été alertés.',
            ], 502);
        }

        // ── PHASE 3 : confirmer le payout ────────────────────────────────────
        DB::table('tondo_payout')
            ->where('id', $payoutId)
            ->update([
                'statut'       => 'succes',
                'operateur_id' => $disburseData['airtel_money_id'] ?? null,
                'response'     => json_encode($disburseData),
                'updated_at'   => now(),
            ]);

        $cagnotte->refresh();

        return response()->json([
            'trans_id'            => $transId,
            'statut'              => 'succes',
            'montant'             => $data['montant'],
            'numero_beneficiaire' => $numeroBeneficiaireE164,
            'montant_collecte'    => (int) $cagnotte->montant_collecte,
        ], 201);
    }
}
