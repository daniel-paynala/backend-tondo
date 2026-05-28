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
    public function show(Request $request, string $reference): JsonResponse
    {
        $cagnotte = TondoCagnotte::where('reference', $reference)->first();

        if (! $cagnotte) {
            return response()->json(['message' => 'Cagnotte introuvable.'], 404);
        }

        // Total collecté depuis les payins confirmés.
        $totalPayin = (int) DB::table('tondo_payin')
            ->where('cagnotte_id', $cagnotte->id)
            ->where('statut', 'succes')
            ->sum('montant');

        // Total décaissé depuis les payouts confirmés.
        $totalPayout = (int) DB::table('tondo_payout')
            ->where('cagnotte_id', $cagnotte->id)
            ->where('statut', 'succes')
            ->sum('montant');

        // Payouts bloqués en statut 'initie' depuis plus de 15 minutes
        // (fenêtre API Paynala) — suspects : Paynala a peut-être répondu mais
        // le backend a planté entre la phase 2 et la phase 3.
        $payoutsInitieAnciens = DB::table('tondo_payout')
            ->where('cagnotte_id', $cagnotte->id)
            ->where('statut', 'initie')
            ->where('date_creation', '<', now()->subMinutes(15))
            ->get(['id', 'trans_id', 'montant', 'numero_tel', 'date_creation']);

        // Payins initiés depuis plus de 10 minutes (polling timed-out côté mobile).
        $payinsInitieAnciens = DB::table('tondo_payin')
            ->where('cagnotte_id', $cagnotte->id)
            ->where('statut', 'initie')
            ->where('date_creation', '<', now()->subMinutes(10))
            ->get(['id', 'trans_id', 'montant', 'numero_tel', 'date_creation']);

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
     * Réconciliation globale : liste toutes les cagnottes avec un écart non nul.
     * Utile pour un audit périodique ou en cas d'incident.
     */
    public function index(): JsonResponse
    {
        $cagnottes = TondoCagnotte::all(['id', 'reference', 'titre', 'montant_collecte']);

        $anomalies = [];

        foreach ($cagnottes as $cagnotte) {
            $totalPayin = (int) DB::table('tondo_payin')
                ->where('cagnotte_id', $cagnotte->id)
                ->where('statut', 'succes')
                ->sum('montant');

            $totalPayout = (int) DB::table('tondo_payout')
                ->where('cagnotte_id', $cagnotte->id)
                ->where('statut', 'succes')
                ->sum('montant');

            $soldeAttendu = $totalPayin - $totalPayout;
            $ecart        = (int) $cagnotte->montant_collecte - $soldeAttendu;

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
