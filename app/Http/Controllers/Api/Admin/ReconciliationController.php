<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Réconciliation financière par cagnotte.
 *
 * Compare le solde courant (`montant_collecte`) avec la somme calculée depuis
 * les tables de transactions. Tout écart indique un bug ou une manipulation
 * directe de la base.
 *
 * GET /api/admin/cagnottes/{reference}/reconcile
 */
class ReconciliationController extends Controller
{
    /**
     * GET /api/admin/cagnottes/{reference}/reconcile
     *
     * Réconciliation financière d'une cagnotte spécifique.
     * Compare `montant_collecte` (colonne dénormalisée) avec le calcul
     * depuis les tables sources : SUM(payin succes) - SUM(payout succes).
     *
     * Un écart non nul signale :
     *  - un bug dans la logique de crédit/débit
     *  - une manipulation directe de la base de données
     *  - une transaction restée en statut 'initie' trop longtemps
     *
     * @param string $reference Référence numérique à 6 chiffres de la cagnotte
     * @return JsonResponse {
     *   reference, titre, solde_actuel, solde_attendu, ecart, is_ok,
     *   total_payin_succes, total_payout_succes,
     *   payouts_initie_anciens, payins_initie_anciens
     * }
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $cagnotte = TondoCagnotte::where('reference', $reference)->first();

        if (! $cagnotte) {
            return response()->json(['message' => 'Cagnotte introuvable.'], 404);
        }

        // Total collecté depuis les payins confirmés (cotisations reçues).
        $totalPayin = (int) DB::table(project_table('payin'))
            ->where('cagnotte_id', $cagnotte->id)
            ->where('statut', 'succes')
            ->sum('montant');

        // Total décaissé depuis les payouts confirmés (reversements effectués).
        $totalPayout = (int) DB::table(project_table('payout'))
            ->where('cagnotte_id', $cagnotte->id)
            ->where('statut', 'succes')
            ->sum('montant');

        // Payouts bloqués en statut 'initie' depuis plus de 15 minutes
        // (fenêtre API Paynala) — suspects : Paynala a peut-être répondu mais
        // le backend a planté entre la phase 2 et la phase 3.
        $payoutsInitieAnciens = DB::table(project_table('payout'))
            ->where('cagnotte_id', $cagnotte->id)
            ->where('statut', 'initie')
            ->where('date_creation', '<', now()->subMinutes(15))
            ->get(['id', 'trans_id', 'montant', 'numero_tel', 'date_creation']);

        // Payins initiés depuis plus de 10 minutes — le mobile a probablement
        // arrêté de poller. À investiguer manuellement si le montant est élevé.
        $payinsInitieAnciens = DB::table(project_table('payin'))
            ->where('cagnotte_id', $cagnotte->id)
            ->where('statut', 'initie')
            ->where('date_creation', '<', now()->subMinutes(10))
            ->get(['id', 'trans_id', 'montant', 'numero_tel', 'date_creation']);

        // Calcul de l'écart : positif = excédent, négatif = manque.
        $soldeAttendu = $totalPayin - $totalPayout;
        $soldeActuel  = (int) $cagnotte->montant_collecte;
        $ecart        = $soldeActuel - $soldeAttendu;
        $isOk         = $ecart === 0;

        return response()->json([
            'reference'             => $cagnotte->reference,
            'titre'                 => $cagnotte->titre,
            'solde_actuel'          => $soldeActuel,
            'solde_attendu'         => $soldeAttendu,
            'ecart'                 => $ecart,
            'is_ok'                 => $isOk,
            'total_payin_succes'    => $totalPayin,
            'total_payout_succes'   => $totalPayout,
            'payouts_initie_anciens' => $payoutsInitieAnciens,
            'payins_initie_anciens'  => $payinsInitieAnciens,
        ]);
    }

    /**
     * GET /api/admin/reconcile
     *
     * Réconciliation globale : liste toutes les cagnottes présentant un écart
     * entre `montant_collecte` et les transactions sources.
     *
     * Utile pour un audit périodique ou pour investiguer un incident de paiement.
     * Charge toutes les cagnottes en mémoire — à optimiser en SQL brut si le
     * volume dépasse quelques milliers.
     *
     * @return JsonResponse { anomalies_count: int, anomalies: array }
     */
    public function index(): JsonResponse
    {
        // Charge uniquement les colonnes nécessaires pour limiter la mémoire.
        $cagnottes = TondoCagnotte::all(['id', 'reference', 'titre', 'montant_collecte']);

        $anomalies = [];

        foreach ($cagnottes as $cagnotte) {
            $totalPayin = (int) DB::table(project_table('payin'))
                ->where('cagnotte_id', $cagnotte->id)
                ->where('statut', 'succes')
                ->sum('montant');

            $totalPayout = (int) DB::table(project_table('payout'))
                ->where('cagnotte_id', $cagnotte->id)
                ->where('statut', 'succes')
                ->sum('montant');

            $soldeAttendu = $totalPayin - $totalPayout;
            $ecart        = (int) $cagnotte->montant_collecte - $soldeAttendu;

            // On n'inclut que les cagnottes avec un écart détecté.
            if ($ecart !== 0) {
                $anomalies[] = [
                    'reference'     => $cagnotte->reference,
                    'titre'         => $cagnotte->titre,
                    'solde_actuel'  => (int) $cagnotte->montant_collecte,
                    'solde_attendu' => $soldeAttendu,
                    'ecart'         => $ecart,
                ];
            }
        }

        return response()->json([
            'anomalies_count' => count($anomalies),
            'anomalies'       => $anomalies,
        ]);
    }
}
