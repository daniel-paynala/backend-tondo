<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use App\Services\PaynalaPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reversements partiels (payout gérant → bénéficiaire).
 *
 * Disponible uniquement pour les cagnottes ouvertes, uniquement pour le
 * créateur. Déduit du montant_collecte après confirmation Paynala disburse.
 *
 * API synchrone : Paynala répond SUCCESS immédiatement, pas besoin de polling.
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
     *   numero_beneficiaire  : string|null  (9 chiffres local, ex : 074577473) — exclusif avec participant_id
     *   participant_id        : string|null  (UUID tondo_participants.id) — exclusif avec numero_beneficiaire
     *   montant              : int           (FCFA, min 100)
     *
     * Exactement l'un des deux (numero_beneficiaire | participant_id) doit être fourni.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cagnotte_reference'  => ['required', 'string', 'regex:/^\d{4,5}$/'],
            'numero_beneficiaire' => ['nullable', 'string', 'regex:/^\d{9}$/'],
            'participant_id'      => ['nullable', 'string', 'uuid'],
            'montant'             => ['required', 'integer', 'min:100', 'max:500000'],
        ]);

        if (empty($data['numero_beneficiaire']) && empty($data['participant_id'])) {
            throw ValidationException::withMessages([
                'numero_beneficiaire' => 'Indiquez un numéro bénéficiaire ou sélectionnez un participant.',
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

        $solde = (int) $cagnotte->montant_collecte;

        if ($data['montant'] > $solde) {
            throw ValidationException::withMessages([
                'montant' => 'Solde insuffisant. Disponible : ' . number_format($solde, 0, ',', ' ') . ' FCFA.',
            ]);
        }

        // Résolution du numéro bénéficiaire selon le mode de saisie.
        if (! empty($data['participant_id'])) {
            $participant = DB::table('tondo_participants')
                ->join('users', 'tondo_participants.user_id', '=', 'users.id')
                ->where('tondo_participants.id', $data['participant_id'])
                ->where('tondo_participants.cagnotte_id', $cagnotte->id)
                ->select('users.numero as numero_user')
                ->first();

            if (! $participant || empty($participant->numero_user)) {
                throw ValidationException::withMessages([
                    'participant_id' => 'Participant introuvable dans cette cagnotte.',
                ]);
            }

            $numeroBeneficiaireE164 = $participant->numero_user;
        } else {
            // Numéro local 9 chiffres → E.164 Gabon
            $numeroBeneficiaireE164 = '+241' . ltrim($data['numero_beneficiaire'], '0');
        }

        $transId = 'TONDOPAYOUT' . strtoupper(Str::random(9));

        // Numéro local 9 chiffres requis par l'API Paynala disburse (ex : 074577473).
        // On strip le +241 et on remet le 0 initial.
        $msisdnLocal = '0' . ltrim(ltrim($numeroBeneficiaireE164, '+'), '241');

        // Référence lisible tronquée à 20 chars (contrainte API).
        $reference = mb_substr($cagnotte->titre ?? $cagnotte->reference, 0, 20);

        // Appel API Paynala disburse — avant la transaction DB pour ne pas
        // déduire le solde si l'API échoue.
        try {
            $disburseData = $this->paynala->disburse(
                idempotencyKey: $transId,
                amount:         $data['montant'],
                msisdn:         $msisdnLocal,
                reference:      $reference,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        try {
            DB::transaction(function () use ($user, $cagnotte, $transId, $data, $numeroBeneficiaireE164, $disburseData) {
                DB::table('tondo_payout')->insert([
                    'id'            => (string) Str::uuid(),
                    'project_id'    => $cagnotte->project_id,
                    'cagnotte_id'   => $cagnotte->id,
                    'user_id'       => $user->id,
                    'trans_id'      => $transId,
                    'operateur_id'  => $disburseData['airtel_money_id'] ?? null,
                    'numero_tel'    => $numeroBeneficiaireE164,
                    'montant'       => $data['montant'],
                    'statut'        => 'succes',
                    'request'       => json_encode([
                        'cagnotte_reference'  => $data['cagnotte_reference'],
                        'numero_beneficiaire' => $numeroBeneficiaireE164,
                        'montant'             => $data['montant'],
                    ]),
                    'response'      => json_encode($disburseData),
                    'date_creation' => now(),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                DB::table('tondo_cagnottes')
                    ->where('id', $cagnotte->id)
                    ->update([
                        'montant_collecte' => DB::raw('montant_collecte - ' . (int) $data['montant']),
                        'updated_at'       => now(),
                    ]);
            });
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Erreur lors du reversement.',
                'error'   => $e->getMessage(),
            ], 500);
        }

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
