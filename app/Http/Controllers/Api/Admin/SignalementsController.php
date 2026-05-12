<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoSignalement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SignalementsController extends Controller
{
    /** GET /api/admin/signalements */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = TondoSignalement::query()
            ->where('project_id', $projectId)
            ->with('cagnotte:id,reference,titre')
            ->when($request->input('statut'), fn ($q, $s) => $q->where('statut', $s))
            ->when($request->input('motif'), fn ($q, $m) => $q->where('motif', $m))
            ->orderByDesc('date_creation');

        return response()->json($query->paginate($perPage));
    }

    /** GET /api/admin/signalements/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $sig = TondoSignalement::with('cagnotte:id,reference,titre')
            ->where('project_id', $request->user()->project_id)
            ->findOrFail($id);

        return response()->json($sig);
    }

    /** PATCH /api/admin/signalements/{id} — change statut, ajoute commentaire. */
    public function update(Request $request, string $id): JsonResponse
    {
        $sig = TondoSignalement::where('project_id', $request->user()->project_id)
            ->findOrFail($id);

        $data = $request->validate([
            'statut' => ['sometimes', Rule::in(['nouveau', 'en_traitement', 'resolu', 'rejete'])],
            'resolu_commentaire' => ['sometimes', 'nullable', 'string'],
        ]);

        if (isset($data['statut']) && in_array($data['statut'], ['resolu', 'rejete'], true)) {
            $sig->resolu_par_admin_id = $request->user()->id;
            $sig->resolu_le = now();
        }

        $sig->fill($data)->save();

        return response()->json($sig);
    }
}
