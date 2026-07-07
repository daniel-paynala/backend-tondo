<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Supervision des utilisateurs mobiles (TondoUser) par les administrateurs.
 *
 * Inclut à la fois les comptes `full` (inscrits normalement) et les comptes
 * `light` (créés automatiquement lors de l'ajout à une tontine).
 * Lecture seule — les modifications de profil se font côté mobile.
 */
class UsersController extends Controller
{
    /**
     * GET /api/admin/users
     *
     * Retourne la liste paginée des utilisateurs du projet avec deux compteurs
     * calculés par sous-requête SQL :
     *  - `cagnottes_count` : nombre de cagnottes créées (rôle gérant)
     *  - `total_cotise`    : somme de toutes les cotisations versées (FCFA)
     *
     * Filtres optionnels :
     *  - `q`              : recherche sur nom, prénom, numéro
     *  - `type_client`    : particulier | entreprise | marchand
     *  - `kyc_valide_only`: boolean — retourne uniquement les KYC validés
     *  - `per_page`       : max 100, défaut 25
     *
     * @return JsonResponse Liste paginée de TondoUser avec compteurs
     */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;
        $perPage = min((int) $request->input('per_page', 25), 100);

        // Noms de tables préfixés selon l'env (tondo_ / tonji_).
        $tCagnottes = project_table('cagnottes');
        $tPaiements = project_table('paiements');

        $query = TondoUser::query()
            ->where('project_id', $projectId)
            // Sous-requêtes corrélées pour éviter les JOINs coûteux sur la liste.
            ->selectRaw("
                users.*,
                (select count(*) from {$tCagnottes} where {$tCagnottes}.user_id = users.id) as cagnottes_count,
                (select coalesce(sum(montant), 0) from {$tPaiements} where {$tPaiements}.user_id = users.id) as total_cotise
            ")
            ->when($request->input('q'), function ($q, $search) {
                // Recherche insensible à la casse sur les identifiants humains.
                $q->where(function ($sub) use ($search) {
                    $sub->where('nom', 'ilike', "%{$search}%")
                        ->orWhere('prenom', 'ilike', "%{$search}%")
                        ->orWhere('numero', 'ilike', "%{$search}%");
                });
            })
            ->when($request->input('type_client'), fn ($q, $t) => $q->where('type_client', $t))
            // Filtre KYC : utile pour cibler les utilisateurs vérifiés uniquement.
            ->when($request->boolean('kyc_valide_only'), fn ($q) => $q->where('kyc_valide', true))
            ->orderByDesc('created_at');

        return response()->json($query->paginate($perPage));
    }

    /**
     * GET /api/admin/users/{id}
     *
     * Retourne le détail complet d'un utilisateur identifié par son UUID.
     * Renvoie 404 si l'utilisateur n'appartient pas au projet courant.
     *
     * @return JsonResponse TondoUser complet
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $projectId = $request->user()->project_id;
        // Scope project_id — isolation multi-tenant obligatoire.
        $user = TondoUser::where('project_id', $projectId)->findOrFail($id);
        return response()->json($user);
    }
}
