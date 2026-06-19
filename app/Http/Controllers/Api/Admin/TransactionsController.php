<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoPayin;
use App\Models\TondoPayout;
use App\Models\TondoPayoutPaynala;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consultation des transactions financières pour les administrateurs.
 *
 * Trois tables sources :
 *  - tondo_payin         : cotisations entrantes (paiements des membres)
 *  - tondo_payout        : reversements sortants vers les bénéficiaires
 *  - tondo_payout_paynala: décaissements initiés via l'API Paynala
 *
 * L'endpoint index() agrège ces trois tables via la vue SQL
 * `tondo_transactions_unified` pour une vue consolidée.
 */
class TransactionsController extends Controller
{
    /**
     * GET /api/admin/transactions
     *
     * Vue unifiée de toutes les transactions du projet via la vue SQL
     * `tondo_transactions_unified` (UNION des trois tables sources).
     *
     * Filtres optionnels :
     *  - `type`    : payin | payout | payout_paynala
     *  - `statut`  : initie | succes | echec
     *  - `q`       : recherche sur trans_id ou operateur_id (référence Paynala)
     *  - `per_page`: max 100, défaut 25
     *
     * @return JsonResponse Liste paginée (vue tondo_transactions_unified)
     */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;
        $perPage = min((int) $request->input('per_page', 25), 100);

        // Requête sur la vue SQL unifiée — pas de modèle Eloquent ici.
        $query = DB::table('tondo_transactions_unified')
            ->where('project_id', $projectId)
            ->when($request->input('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->input('statut'), fn ($q, $s) => $q->where('statut', $s))
            ->when($request->input('q'), function ($q, $search) {
                // Recherche sur l'identifiant interne ou l'ID Paynala/Airtel.
                $q->where(function ($sub) use ($search) {
                    $sub->where('trans_id', 'ilike', "%{$search}%")
                        ->orWhere('operateur_id', 'ilike', "%{$search}%");
                });
            })
            ->orderByDesc('date_creation');

        return response()->json($query->paginate($perPage));
    }

    /**
     * GET /api/admin/transactions/payin
     *
     * Liste paginée des cotisations entrantes (tondo_payin).
     * Filtres : statut, cagnotte_id.
     *
     * @return JsonResponse Liste paginée de TondoPayin
     */
    public function payin(Request $request): JsonResponse
    {
        return $this->listFor($request, TondoPayin::class);
    }

    /**
     * GET /api/admin/transactions/payout
     *
     * Liste paginée des reversements sortants (tondo_payout).
     * Filtres : statut, cagnotte_id.
     *
     * @return JsonResponse Liste paginée de TondoPayout
     */
    public function payout(Request $request): JsonResponse
    {
        return $this->listFor($request, TondoPayout::class);
    }

    /**
     * GET /api/admin/transactions/payout-paynala
     *
     * Liste paginée des décaissements via l'API Paynala (tondo_payout_paynala).
     * Filtres : statut, cagnotte_id.
     *
     * @return JsonResponse Liste paginée de TondoPayoutPaynala
     */
    public function payoutPaynala(Request $request): JsonResponse
    {
        return $this->listFor($request, TondoPayoutPaynala::class);
    }

    /**
     * Helper générique pour lister une table transactionnelle avec filtres communs.
     *
     * @param string $modelClass Classe Eloquent à interroger (TondoPayin, TondoPayout…)
     * @return JsonResponse Liste paginée du modèle donné
     */
    private function listFor(Request $request, string $modelClass): JsonResponse
    {
        $projectId = $request->user()->project_id;
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = $modelClass::query()
            ->where('project_id', $projectId)
            ->when($request->input('statut'), fn ($q, $s) => $q->where('statut', $s))
            // Permet de filtrer toutes les transactions d'une cagnotte donnée.
            ->when($request->input('cagnotte_id'), fn ($q, $c) => $q->where('cagnotte_id', $c))
            ->orderByDesc('date_creation');

        return response()->json($query->paginate($perPage));
    }
}
