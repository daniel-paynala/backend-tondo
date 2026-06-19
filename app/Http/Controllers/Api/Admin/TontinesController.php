<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Supervision des tontines et cagnottes ouvertes par les administrateurs.
 *
 * Ce contrôleur donne une vue globale sur toutes les cagnottes du projet,
 * indépendamment de leur créateur. Les admins peuvent consulter le détail
 * et forcer une clôture en cas de litige ou d'anomalie.
 *
 * Note : "tontines" dans le nom de route couvre les deux types :
 *  - tontine_periodique  (rotations avec cycle fixe)
 *  - cagnotte_ouverte    (collecte libre sans cycle)
 */
class TontinesController extends Controller
{
    /**
     * GET /api/admin/tontines
     *
     * Retourne la liste paginée de toutes les cagnottes du projet.
     * Enrichit chaque ligne avec `gerant_libelle` (prénom + nom du créateur)
     * via une sous-requête SQL pour éviter le N+1.
     *
     * Filtres optionnels :
     *  - `q`       : recherche sur titre ou référence
     *  - `type`    : tontine_periodique | cagnotte_ouverte
     *  - `statut`  : active | en_cours | cloturee
     *  - `per_page`: max 100, défaut 25
     *
     * @return JsonResponse Liste paginée de TondoCagnotte avec gerant_libelle
     */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = TondoCagnotte::query()
            ->where('project_id', $projectId)
            // Sous-requête corrélée pour obtenir le nom du gérant sans JOIN.
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

    /**
     * GET /api/admin/tontines/{id}
     *
     * Retourne le détail complet d'une cagnotte avec ses relations :
     *  - `gerant`       : le créateur (TondoUser)
     *  - `participants` : liste des participants inscrits
     *
     * @return JsonResponse TondoCagnotte avec relations gerant et participants
     */
    public function show(Request $request, string $id): JsonResponse
    {
        // Eager-load des relations pour éviter les requêtes N+1 sur le détail.
        $cagnotte = TondoCagnotte::with(['gerant', 'participants'])
            ->where('project_id', $request->user()->project_id)
            ->findOrFail($id);

        return response()->json($cagnotte);
    }

    /**
     * POST /api/admin/tontines/{id}/cloturer
     *
     * Force la clôture d'une cagnotte par un administrateur.
     * Idempotent : si déjà clôturée, retourne l'état courant sans erreur.
     * Cas d'usage : litige non résolu, inactivité prolongée, demande utilisateur.
     *
     * @return JsonResponse TondoCagnotte avec statut = 'cloturee'
     */
    public function cloturer(Request $request, string $id): JsonResponse
    {
        $cagnotte = TondoCagnotte::where('project_id', $request->user()->project_id)
            ->findOrFail($id);

        // Idempotent : pas d'erreur si déjà clôturée.
        if ($cagnotte->statut === 'cloturee') {
            return response()->json($cagnotte);
        }

        $cagnotte->statut = 'cloturee';
        $cagnotte->save();

        return response()->json($cagnotte);
    }
}
