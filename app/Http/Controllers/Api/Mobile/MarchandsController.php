<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Marchands proposés au client au moment d'un transfert.
 *
 * Le client ne voit que les fiches actives : une fiche désactivée reste dans
 * l'historique mais ne doit plus être proposée. La liste est courte par nature,
 * elle est donc renvoyée d'un bloc, avec les catégories pour le classement à
 * l'écran.
 */
class MarchandsController extends Controller
{
    /**
     * GET /api/mobile/marchands
     * Paramètre facultatif : q (recherche sur le nom).
     */
    public function index(Request $request): JsonResponse
    {
        $marchands  = project_table('marchands');
        $categories = project_table('categories_marchands');

        $lignes = DB::table($marchands)
            ->leftJoin($categories, "{$categories}.id", '=', "{$marchands}.categorie_id")
            ->where("{$marchands}.project_id", $request->user()->project_id)
            ->where("{$marchands}.actif", true)
            ->when($request->filled('q'), function ($q) use ($request, $marchands) {
                $terme = '%' . trim((string) $request->input('q')) . '%';
                $q->where("{$marchands}.nom", 'ilike', $terme);
            })
            ->orderBy("{$marchands}.nom")
            ->get([
                "{$marchands}.id",
                "{$marchands}.nom",
                "{$marchands}.numero_tel",
                "{$marchands}.titulaire",
                "{$marchands}.ville",
                "{$categories}.libelle as categorie",
            ]);

        return response()->json([
            'marchands' => $lignes->map(fn ($m) => [
                'id'        => $m->id,
                'nom'       => $m->nom,
                // Numéro affiché au client : c'est ce qui sera débité, il doit
                // pouvoir le lire avant de valider.
                'numero'    => $m->numero_tel,
                'titulaire' => $m->titulaire,
                'ville'     => $m->ville,
                'categorie' => $m->categorie,
            ]),
            // Le client range la liste par catégorie ; l'ordre vient d'ici pour
            // que l'app n'ait pas à le deviner.
            'categories' => $lignes->pluck('categorie')->filter()->unique()->sort()->values(),
        ]);
    }
}
