<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogsController extends Controller
{
    /** GET /api/admin/logs */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;
        $perPage = min((int) $request->input('per_page', 50), 200);

        $query = TondoLog::query()
            ->where('project_id', $projectId)
            ->when($request->input('niveau'), fn ($q, $n) => $q->where('niveau', $n))
            ->when($request->input('acteur_role'), fn ($q, $r) => $q->where('acteur_role', $r))
            ->when($request->input('q'), function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('action', 'ilike', "%{$search}%")
                        ->orWhere('cible', 'ilike', "%{$search}%")
                        ->orWhere('acteur_libelle', 'ilike', "%{$search}%");
                });
            })
            ->orderByDesc('date');

        return response()->json($query->paginate($perPage));
    }
}
