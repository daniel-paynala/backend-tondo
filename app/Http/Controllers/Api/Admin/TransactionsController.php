<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoPayin;
use App\Models\TondoPayout;
use App\Models\TondoPayoutPaynala;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionsController extends Controller
{
    /**
     * GET /api/admin/transactions
     *
     * Liste unifiée des 3 tables transactionnelles via la vue
     * `tondo_transactions_unified`. Filtres : type, statut, q (search sur trans_id).
     */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = DB::table('tondo_transactions_unified')
            ->where('project_id', $projectId)
            ->when($request->input('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->input('statut'), fn ($q, $s) => $q->where('statut', $s))
            ->when($request->input('q'), function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('trans_id', 'ilike', "%{$search}%")
                        ->orWhere('operateur_id', 'ilike', "%{$search}%");
                });
            })
            ->orderByDesc('date_creation');

        return response()->json($query->paginate($perPage));
    }

    /** GET /api/admin/transactions/payin */
    public function payin(Request $request): JsonResponse
    {
        return $this->listFor($request, TondoPayin::class);
    }

    /** GET /api/admin/transactions/payout */
    public function payout(Request $request): JsonResponse
    {
        return $this->listFor($request, TondoPayout::class);
    }

    /** GET /api/admin/transactions/payout-paynala */
    public function payoutPaynala(Request $request): JsonResponse
    {
        return $this->listFor($request, TondoPayoutPaynala::class);
    }

    private function listFor(Request $request, string $modelClass): JsonResponse
    {
        $projectId = $request->user()->project_id;
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = $modelClass::query()
            ->where('project_id', $projectId)
            ->when($request->input('statut'), fn ($q, $s) => $q->where('statut', $s))
            ->when($request->input('cagnotte_id'), fn ($q, $c) => $q->where('cagnotte_id', $c))
            ->orderByDesc('date_creation');

        return response()->json($query->paginate($perPage));
    }
}
