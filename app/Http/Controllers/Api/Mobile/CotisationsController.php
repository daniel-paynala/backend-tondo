<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cotisations entrantes (payin).
 *
 * Le cotisant cotise sur une cagnotte identifiée par sa `reference`
 * (4-5 chiffres, partagée par le gérant). Frais 2 % à la charge du
 * cotisant (RÈGLE 4-bis). En mode test l'agrégateur paiement n'est
 * pas branché : la transaction passe directement en statut `succes`
 * pour pouvoir tester le flow E2E. Quand on branchera Airtel Money
 * / Moov / un agrégateur, le statut passera par `initie → en_cours
 * → succes|echec` via callbacks asynchrones.
 */
class CotisationsController extends Controller
{
    /** Frais Paynala — 2 % du montant net. */
    private const FRAIS_PAYNALA_RATIO = 0.02;

    /**
     * POST /api/mobile/cotisations
     * Body : {
     *   cagnotte_reference: string,   // 4-5 chiffres
     *   montant: int,                  // FCFA, net que touche la cagnotte
     *   indicatif_payeur?: string,    // override du numéro user authentifié
     *   numero_payeur?: string,
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cagnotte_reference' => ['required', 'string', 'regex:/^\d{4,5}$/'],
            'montant' => ['required', 'integer', 'min:100', 'max:500000'],
            'indicatif_payeur' => ['nullable', 'string', 'regex:/^\+?\d{1,4}$/'],
            'numero_payeur' => ['nullable', 'string', 'regex:/^\d{6,12}$/'],
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
        if ($cagnotte->statut !== 'active') {
            throw ValidationException::withMessages([
                'cagnotte_reference' => 'Cagnotte clôturée — cotisation impossible.',
            ]);
        }

        // Numéro qui paie. Par défaut celui de l'user authentifié.
        $numeroPayeur = $user->numero;
        if (! empty($data['indicatif_payeur']) && ! empty($data['numero_payeur'])) {
            $numeroPayeur = '+' . ltrim($data['indicatif_payeur'], '+') . ltrim($data['numero_payeur'], '0');
        }

        $montantNet = $data['montant'];
        $frais = (int) round($montantNet * self::FRAIS_PAYNALA_RATIO);
        $montantBrut = $montantNet + $frais;
        $transId = 'TONDO-PAYIN-' . strtoupper(Str::random(10));

        try {
            DB::transaction(function () use (
                $user,
                $cagnotte,
                $montantNet,
                $montantBrut,
                $numeroPayeur,
                $transId
            ) {
                // 1) Récupère ou crée le participant pour cet user dans cette cagnotte.
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
                    // Pour une cagnotte ouverte, nombre_participants = inscrits réels.
                    // Pour une tontine, nombre_participants = cible déclarée (immuable) ;
                    // seul nombre_inscrits évolue.
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
                            'statut_paiement' => 'paye',
                            'montant_paye' => DB::raw('montant_paye + ' . $montantNet),
                            'date_dernier_paiement' => now(),
                        ]);
                }

                // 2) Enregistre le paiement (audit fonctionnel).
                DB::table('tondo_paiements')->insert([
                    'id' => (string) Str::uuid(),
                    'project_id' => $cagnotte->project_id,
                    'cagnotte_id' => $cagnotte->id,
                    'participant_id' => $participantId,
                    'user_id' => $user->id,
                    'montant' => $montantNet,
                    'date' => now(),
                    'created_at' => now(),
                ]);

                // 3) Trace la transaction payin (audit financier réconciliation).
                DB::table('tondo_payin')->insert([
                    'id' => (string) Str::uuid(),
                    'project_id' => $cagnotte->project_id,
                    'cagnotte_id' => $cagnotte->id,
                    'user_id' => $user->id,
                    'trans_id' => $transId,
                    'operateur_id' => 'MOCK-' . substr($transId, -8),
                    'numero_tel' => $numeroPayeur,
                    'montant' => $montantBrut,
                    'statut' => 'succes', // mock : pas d'agrégateur encore
                    'request' => json_encode(['note' => 'mock — agrégateur non branché']),
                    'response' => json_encode(['ok' => true, 'mocked' => true]),
                    'date_creation' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // 4) Crédite la cagnotte (montant net, hors frais).
                DB::table('tondo_cagnottes')
                    ->where('id', $cagnotte->id)
                    ->update([
                        'montant_collecte' => DB::raw('montant_collecte + ' . $montantNet),
                        'updated_at' => now(),
                    ]);
            });
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Cotisation échouée.',
                'error' => $e->getMessage(),
            ], 500);
        }

        // Recharge la cagnotte pour renvoyer l'état à jour.
        $cagnotte->refresh();

        return response()->json([
            'trans_id' => $transId,
            'statut' => 'succes',
            'montant_net' => $montantNet,
            'frais' => $frais,
            'montant_brut' => $montantBrut,
            'cagnotte' => [
                'reference' => $cagnotte->reference,
                'titre' => $cagnotte->titre,
                'montant_collecte' => (int) $cagnotte->montant_collecte,
                'nombre_participants' => $cagnotte->nombre_participants,
            ],
        ], 201);
    }

    private function maskPhone(string $phone): string
    {
        $clean = preg_replace('/[^\d+]/', '', $phone);
        if (strlen($clean) < 6) {
            return $clean;
        }
        $prefix = substr($clean, 0, strlen($clean) - 6);
        $last2 = substr($clean, -2);

        return $prefix . str_repeat('*', strlen($clean) - strlen($prefix) - 2) . $last2;
    }
}
