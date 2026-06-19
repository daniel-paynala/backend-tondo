<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoSignalement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestion des signalements (litiges, abus, problèmes) remontés par les utilisateurs.
 *
 * Rappel règle métier : Paynala n'arbitre pas les litiges entre membres
 * (RÈGLE 3 du CLAUDE.md). Le rôle de ce contrôleur est de permettre
 * aux admins de tracer et classer les signalements, pas de prendre des
 * décisions juridiques à leur place.
 *
 * Statuts possibles : nouveau | en_traitement | resolu | rejete
 */
class SignalementsController extends Controller
{
    /**
     * GET /api/admin/signalements
     *
     * Retourne la liste paginée des signalements du projet courant.
     * Inclut la relation `cagnotte` (reference + titre) pour l'affichage.
     * Filtres optionnels :
     *  - `statut`   : nouveau | en_traitement | resolu | rejete
     *  - `motif`    : motif déclaré par l'utilisateur
     *  - `per_page` : max 100, défaut 25
     *
     * @return JsonResponse Liste paginée de TondoSignalement
     */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = TondoSignalement::query()
            ->where('project_id', $projectId)
            // Eager-load minimal pour éviter le N+1 sur la liste.
            ->with('cagnotte:id,reference,titre')
            ->when($request->input('statut'), fn ($q, $s) => $q->where('statut', $s))
            ->when($request->input('motif'), fn ($q, $m) => $q->where('motif', $m))
            ->orderByDesc('date_creation');

        return response()->json($query->paginate($perPage));
    }

    /**
     * GET /api/admin/signalements/{id}
     *
     * Retourne le détail complet d'un signalement avec sa cagnotte associée.
     * Renvoie 404 si le signalement n'appartient pas au projet courant.
     *
     * @return JsonResponse TondoSignalement avec relation cagnotte
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $sig = TondoSignalement::with('cagnotte:id,reference,titre')
            ->where('project_id', $request->user()->project_id)
            ->findOrFail($id);

        return response()->json($sig);
    }

    /**
     * PATCH /api/admin/signalements/{id}
     *
     * Met à jour le statut et/ou le commentaire d'un signalement.
     * Body : { statut?, resolu_commentaire? }
     *
     * Si statut = 'resolu' ou 'rejete', horodate la résolution et enregistre
     * l'identifiant de l'admin qui a traité le signalement.
     *
     * @return JsonResponse TondoSignalement mis à jour
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $sig = TondoSignalement::where('project_id', $request->user()->project_id)
            ->findOrFail($id);

        $data = $request->validate([
            'statut' => ['sometimes', Rule::in(['nouveau', 'en_traitement', 'resolu', 'rejete'])],
            'resolu_commentaire' => ['sometimes', 'nullable', 'string'],
        ]);

        // Horodatage de clôture uniquement pour les statuts terminaux.
        if (isset($data['statut']) && in_array($data['statut'], ['resolu', 'rejete'], true)) {
            $sig->resolu_par_admin_id = $request->user()->id;
            $sig->resolu_le = now();
        }

        $sig->fill($data)->save();

        return response()->json($sig);
    }
}
