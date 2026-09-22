<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\GereRetraitAgents;
use App\Http\Controllers\Controller;
use App\Models\TondoCategorieMarchand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paramètres — catégories de marchands.
 *
 * Même logique que les supports de retrait : une liste courte, administrée,
 * qui alimente un choix dans le formulaire plutôt qu'un champ libre. Une
 * catégorie déjà utilisée ne se supprime pas, elle se désactive : sinon les
 * fiches existantes perdraient leur classement.
 */
class CategoriesMarchandsController extends Controller
{
    use GereRetraitAgents;

    /** GET /api/admin/categories-marchands */
    public function index(Request $request): JsonResponse
    {
        $categories = TondoCategorieMarchand::where('project_id', $request->user()->project_id)
            ->withCount('marchands')
            ->orderBy('libelle')
            ->get();

        return response()->json([
            'categories' => $categories->map(fn (TondoCategorieMarchand $c) => $this->presenter($c)),
        ]);
    }

    /** POST /api/admin/categories-marchands */
    public function store(Request $request): JsonResponse
    {
        $this->exigerSuperAdmin($request);
        $projectId = $request->user()->project_id;

        $data = $request->validate($this->regles($projectId));

        $categorie = new TondoCategorieMarchand();
        // id généré en PHP : Eloquent ne relit pas la valeur du DEFAULT.
        $categorie->id = (string) Str::uuid();
        $categorie->fill($data + ['project_id' => $projectId]);
        $categorie->save();

        $this->journaliser($request, 'categorie_marchand_creee', "Catégorie {$categorie->libelle}", 'info', [
            'categorie_id' => $categorie->id,
        ]);

        return response()->json(['categorie' => $this->presenter($categorie->loadCount('marchands'))], 201);
    }

    /** PATCH /api/admin/categories-marchands/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);
        $projectId = $request->user()->project_id;

        $categorie = TondoCategorieMarchand::where('project_id', $projectId)->withCount('marchands')->find($id);
        if (! $categorie) {
            return response()->json(['message' => 'Catégorie introuvable.'], 404);
        }

        $data = $request->validate($this->regles($projectId, $categorie->id, partiel: true));

        $avant = $categorie->only(array_keys($data));
        $categorie->fill($data);
        $categorie->save();

        $this->journaliser($request, 'categorie_marchand_modifiee', "Catégorie {$categorie->libelle}", 'info', [
            'categorie_id' => $categorie->id,
            'avant'        => $avant,
            'apres'        => $data,
        ]);

        return response()->json(['categorie' => $this->presenter($categorie)]);
    }

    /** DELETE /api/admin/categories-marchands/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);

        $categorie = TondoCategorieMarchand::where('project_id', $request->user()->project_id)
            ->withCount('marchands')->find($id);
        if (! $categorie) {
            return response()->json(['message' => 'Catégorie introuvable.'], 404);
        }

        if ($categorie->marchands_count > 0) {
            return response()->json([
                'message' => "Suppression impossible : {$categorie->marchands_count} marchand(s) utilisent cette catégorie. Désactivez-la plutôt.",
            ], 409);
        }

        $categorie->delete();

        $this->journaliser($request, 'categorie_marchand_supprimee', "Catégorie {$categorie->libelle}", 'warning', [
            'categorie_id' => $categorie->id,
        ]);

        return response()->json(['supprime' => true]);
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function regles(string $projectId, ?string $ignorerId = null, bool $partiel = false): array
    {
        $requis = $partiel ? 'sometimes' : 'required';
        $table  = project_table('categories_marchands');

        return [
            'libelle' => [$requis, 'string', 'min:2', 'max:40',
                // Unicité insensible à la casse, comme l'index en base : le
                // doublon « Santé » / « santé » est exactement ce qu'on évite.
                function (string $attribut, mixed $valeur, \Closure $echec) use ($table, $projectId, $ignorerId) {
                    $existe = DB::table($table)
                        ->where('project_id', $projectId)
                        ->whereRaw('lower(libelle) = ?', [mb_strtolower(trim((string) $valeur))])
                        ->when($ignorerId, fn ($q) => $q->where('id', '!=', $ignorerId))
                        ->exists();
                    if ($existe) {
                        $echec('Cette catégorie existe déjà.');
                    }
                }],
            'description' => ['sometimes', 'nullable', 'string', 'max:160'],
            'actif'       => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private function presenter(TondoCategorieMarchand $c): array
    {
        $nb = $c->marchands_count ?? $c->marchands()->count();

        return [
            'id'           => $c->id,
            'libelle'      => $c->libelle,
            'description'  => $c->description,
            'actif'        => (bool) $c->actif,
            'nb_marchands' => (int) $nb,
            'supprimable'  => $nb === 0,
        ];
    }
}
