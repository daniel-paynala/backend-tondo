<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsersController extends Controller
{
    /** GET /api/admin/users — liste paginée avec compteurs cagnottes + total cotisé. */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = TondoUser::query()
            ->where('project_id', $projectId)
            ->selectRaw('
                users.*,
                (select count(*) from tondo_cagnottes where tondo_cagnottes.user_id = users.id) as cagnottes_count,
                (select coalesce(sum(montant), 0) from tondo_paiements where tondo_paiements.user_id = users.id) as total_cotise
            ')
            ->when($request->input('q'), function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('nom', 'ilike', "%{$search}%")
                        ->orWhere('prenom', 'ilike', "%{$search}%")
                        ->orWhere('numero', 'ilike', "%{$search}%");
                });
            })
            ->when($request->input('type_client'), fn ($q, $t) => $q->where('type_client', $t))
            ->when($request->boolean('kyc_valide_only'), fn ($q) => $q->where('kyc_valide', true))
            ->orderByDesc('created_at');

        return response()->json($query->paginate($perPage));
    }

    /** GET /api/admin/users/{id} — détail d'un user. */
    public function show(Request $request, string $id): JsonResponse
    {
        $projectId = $request->user()->project_id;
        $user = TondoUser::where('project_id', $projectId)->findOrFail($id);
        return response()->json($user);
    }
}
