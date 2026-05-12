<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TontinesController extends Controller
{
    /** GET /api/admin/tontines — liste des cagnottes (tontines + cotisations). */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = TondoCagnotte::query()
            ->where('project_id', $projectId)
            ->selectRaw('
                tondo_cagnottes.*,
                (select prenom || \' \' || nom from users where users.id = tondo_cagnottes.user_id) as gerant_libelle
            ')
            ->when($request->input('q'), function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('titre', 'ilike', "%{$search}%")
                        ->orWhere('reference', 'ilike', "%{$search}%");
                });
            })
            ->when($request->input('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->input('statut'), fn ($q, $s) => $q->where('statut', $s))
            ->orderByDesc('date_creation');

        return response()->json($query->paginate($perPage));
    }

    /** GET /api/admin/tontines/{id} — détail avec participants. */
    public function show(Request $request, string $id): JsonResponse
    {
        $cagnotte = TondoCagnotte::with(['gerant', 'participants'])
            ->where('project_id', $request->user()->project_id)
            ->findOrFail($id);

        return response()->json($cagnotte);
    }

    /** POST /api/admin/tontines/{id}/cloturer — clôture manuelle par un admin. */
    public function cloturer(Request $request, string $id): JsonResponse
    {
        $cagnotte = TondoCagnotte::where('project_id', $request->user()->project_id)
            ->findOrFail($id);

        if ($cagnotte->statut === 'cloturee') {
            return response()->json($cagnotte);
        }

        $cagnotte->statut = 'cloturee';
        $cagnotte->save();

        return response()->json($cagnotte);
    }
}
