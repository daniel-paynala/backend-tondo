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
            ->get(self::colonnes($marchands, $categories));

        return response()->json([
            'marchands' => $lignes->map(fn ($m) => self::presenter($m)),
            // Le client range la liste par catégorie ; l'ordre vient d'ici pour
            // que l'app n'ait pas à le deviner.
            'categories' => $lignes->pluck('categorie')->filter()->unique()->sort()->values(),
        ]);
    }

    /**
     * GET /api/mobile/marchands/resoudre?saisie=...
     *
     * Le client tape dans un seul champ soit le numéro de l'enseigne, soit son
     * code. On ne devine pas lequel : les deux pistes sont cherchées, et tout
     * ce qui correspond est renvoyé.
     *
     * **Plusieurs réponses sont normales.** Depuis 031, une chaîne encaisse sur
     * un seul numéro pour plusieurs points de vente : le numéro seul ne dit
     * donc pas QUI est payé, et c'est précisément ce que le code lève. Quand
     * plusieurs fiches répondent, c'est à l'utilisateur de désigner celle qu'il
     * paie — l'app ne choisit pas à sa place, le nom affiché sur le bouton
     * doit être celui qu'il a validé.
     */
    public function resoudre(Request $request): JsonResponse
    {
        $data = $request->validate([
            'saisie' => ['required', 'string', 'max:32'],
        ]);

        $saisie = trim($data['saisie']);
        if ($saisie === '') {
            return response()->json(['marchands' => []]);
        }

        // Le champ client est sans indicatif : un numéro arrive en 9 chiffres
        // locaux, alors que les fiches stockent l'E.164. On accepte aussi la
        // forme internationale, au cas où elle serait collée depuis ailleurs.
        $numero = null;
        if (preg_match('/^0\d{8}$/', $saisie)) {
            $numero = '+241' . substr($saisie, 1);
        } elseif (preg_match('/^\+241\d{8,9}$/', $saisie)) {
            $numero = $saisie;
        }

        $marchands  = project_table('marchands');
        $categories = project_table('categories_marchands');

        $lignes = DB::table($marchands)
            ->leftJoin($categories, "{$categories}.id", '=', "{$marchands}.categorie_id")
            ->where("{$marchands}.project_id", $request->user()->project_id)
            ->where("{$marchands}.actif", true)
            ->where(function ($q) use ($marchands, $saisie, $numero) {
                // Code : comparé sans tenir compte de la casse, le client le
                // tape comme il l'a lu sur une devanture ou un reçu.
                $q->whereRaw("upper({$marchands}.code_marchand) = ?", [mb_strtoupper($saisie)]);
                if ($numero !== null) {
                    $q->orWhere("{$marchands}.numero_tel", $numero);
                }
            })
            ->orderBy("{$marchands}.nom")
            ->get(self::colonnes($marchands, $categories));

        return response()->json([
            'marchands' => $lignes->map(fn ($m) => self::presenter($m))->values(),
        ]);
    }

    /**
     * Colonnes lues pour une fiche marchand — identiques dans les deux routes.
     *
     * @return array<int, string>
     */
    private static function colonnes(string $marchands, string $categories): array
    {
        return [
            "{$marchands}.id",
            "{$marchands}.nom",
            "{$marchands}.code_marchand",
            "{$marchands}.numero_tel",
            "{$marchands}.titulaire",
            "{$marchands}.ville",
            "{$categories}.libelle as categorie",
        ];
    }

    /**
     * Forme envoyée à l'app. Une seule définition : la liste du carnet et la
     * résolution par saisie doivent rendre exactement la même chose, sinon
     * l'app aurait deux façons de lire un marchand.
     *
     * @return array<string, mixed>
     */
    private static function presenter(object $m): array
    {
        return [
            'id'        => $m->id,
            'nom'       => $m->nom,
            'code'      => $m->code_marchand,
            // Numéro affiché au client : c'est ce qui sera débité, il doit
            // pouvoir le lire avant de valider.
            'numero'    => $m->numero_tel,
            'titulaire' => $m->titulaire,
            'ville'     => $m->ville,
            'categorie' => $m->categorie,
        ];
    }
}
