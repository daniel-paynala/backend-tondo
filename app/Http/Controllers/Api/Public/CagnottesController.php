<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints PUBLICS (sans authentification) des cagnottes publiques approuvées.
 *
 * Alimente la page « Explorer » de l'app mobile (et, plus tard, le web).
 * Ne renvoie QUE des cagnottes : visibilité publique + validées par un admin
 * + de type « cagnotte ouverte » + actives.
 */
class CagnottesController extends Controller
{
    /**
     * Base commune : seules les publiques approuvées et actives sont exposées.
     */
    private function baseQuery()
    {
        return TondoCagnotte::query()
            ->where('visibilite', 'public')
            ->where('statut_validation', 'approuvee')
            ->where('type', 'cagnotte_ouverte')
            ->where('statut', 'active');
    }

    /**
     * GET /api/public/cagnottes?limit=&offset=&tri=recentes|objectif
     * Liste paginée des cagnottes publiques (page Explorer).
     */
    public function index(Request $request): JsonResponse
    {
        $limit  = min(max((int) $request->integer('limit', 20), 1), 50);
        $offset = max((int) $request->integer('offset', 0), 0);
        $tri    = $request->string('tri')->toString();

        $q = $this->baseQuery();
        if ($tri === 'objectif') {
            // Les plus proches d'atteindre leur objectif d'abord (Postgres).
            $q->orderByRaw('CASE WHEN montant_cible IS NULL OR montant_cible = 0 THEN 1 ELSE 0 END')
              ->orderByRaw('(montant_collecte::float / NULLIF(montant_cible, 0)) DESC');
        } else {
            $q->orderBy('date_creation', 'desc'); // défaut : les plus récentes
        }

        $total = (clone $q)->count();
        $items = $q->offset($offset)->limit($limit)->get();

        // Pré-charge les créateurs en une requête (évite le N+1).
        $createurs = TondoUser::whereIn('id', $items->pluck('user_id'))->get()->keyBy('id');

        return response()->json([
            'data'  => $items->map(fn ($c) => $this->carte($c, $createurs[$c->user_id] ?? null))->all(),
            'total' => $total,
        ]);
    }

    /**
     * GET /api/public/cagnottes/{reference}
     * Détail public d'une cagnotte (page publique / partage).
     */
    public function show(string $reference): JsonResponse
    {
        $c = $this->baseQuery()->where('reference', $reference)->first();
        if (! $c) {
            return response()->json(['message' => 'Cagnotte introuvable ou non publique.'], 404);
        }

        return response()->json([
            'cagnotte' => $this->carte($c, TondoUser::find($c->user_id)),
        ]);
    }

    /**
     * Sérialise une cagnotte publique pour l'affichage (carte Explorer / détail).
     * Aucune donnée sensible : pas de numéro de retrait, pas d'IDs internes.
     */
    private function carte(TondoCagnotte $c, ?TondoUser $createur): array
    {
        return [
            'reference'        => $c->reference,
            'titre'            => $c->titre,
            'description'      => $c->description,
            'montant_collecte' => (int) $c->montant_collecte,
            'montant_cible'    => $c->montant_cible,
            'nombre_inscrits'  => (int) $c->nombre_inscrits,
            'date_creation'    => $c->date_creation?->toIso8601String(),
            'date_fin'         => $c->date_fin?->toIso8601String(),
            'createur'         => $createur
                ? trim(ucfirst(mb_strtolower((string) $createur->prenom)) . ' ' . mb_strtoupper((string) $createur->nom))
                : null,
        ];
    }
}
