<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journal d'audit du projet Tondo.
 *
 * Expose la table `tondo_logs` qui enregistre toutes les actions
 * significatives (connexions, créations, modifications, paiements...).
 * Lecture seule — aucune écriture via l'API.
 */
class LogsController extends Controller
{
    /**
     * GET /api/admin/logs
     *
     * Retourne le journal d'audit paginé du projet courant.
     * Filtres optionnels :
     *  - `niveau`      : niveau de sévérité (info | warning | error | critical)
     *  - `acteur_role` : role de l'acteur (admin | user | system | webhook)
     *  - `q`           : recherche plein-texte sur action, cible, acteur_libelle
     *  - `per_page`    : max 200, défaut 50
     *
     * Tri : par date décroissante (entrées les plus récentes en premier).
     *
     * @return JsonResponse Liste paginée de TondoLog
     */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;
        // Plafond plus élevé que pour les autres listes (logs = volume élevé).
        $perPage = min((int) $request->input('per_page', 50), 200);

        $query = TondoLog::query()
            ->where('project_id', $projectId)
            // Filtre par niveau de sévérité si fourni.
            ->when($request->input('niveau'), fn ($q, $n) => $q->where('niveau', $n))
            // Filtre par rôle de l'acteur (admin, user, system, webhook...).
            ->when($request->input('acteur_role'), fn ($q, $r) => $q->where('acteur_role', $r))
            ->when($request->input('q'), function ($q, $search) {
                // Recherche dans les trois colonnes textuelles principales.
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
