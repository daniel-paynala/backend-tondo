<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\GereRetraitAgents;
use App\Http\Controllers\Controller;
use App\Models\TondoPartenaireRetrait;
use App\Support\RetraitAgents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Paramètres — partenaires de retrait (Ecobank, UBA, Sengab…).
 *
 * Le partenaire porte la clé d'API : c'est son système qui la présente, puis
 * l'agent son PIN. La clé n'est montrée qu'UNE fois, à la création ou à la
 * réémission ; seule son empreinte est conservée.
 *
 * Comme pour les supports : sigle figé dès qu'un agent l'utilise, et
 * désactivation plutôt que suppression.
 */
class PartenairesRetraitController extends Controller
{
    use GereRetraitAgents;

    /** GET /api/admin/partenaires-retrait */
    public function index(Request $request): JsonResponse
    {
        $partenaires = TondoPartenaireRetrait::where('project_id', $request->user()->project_id)
            ->withCount('agents')
            ->orderBy('nom')
            ->get();

        return response()->json([
            'partenaires' => $partenaires->map(fn (TondoPartenaireRetrait $p) => $this->presenter($p)),
        ]);
    }

    /**
     * POST /api/admin/partenaires-retrait
     *
     * Crée le partenaire et émet sa première clé, présente dans CETTE réponse
     * et nulle part ailleurs.
     */
    public function store(Request $request): JsonResponse
    {
        $this->exigerSuperAdmin($request);
        $projectId = $request->user()->project_id;

        $request->merge(['sigle' => RetraitAgents::normaliserSigle($request->input('sigle'))]);
        $data = $request->validate($this->regles($projectId));

        $cle = RetraitAgents::genererCleApi($data['sigle']);

        $partenaire = new TondoPartenaireRetrait();
        // id généré en PHP : Eloquent ne relit pas la valeur du DEFAULT.
        $partenaire->id = (string) Str::uuid();
        $partenaire->fill($data + [
            'project_id'       => $projectId,
            'cle_api_hash'     => $cle['hash'],
            'cle_api_apercu'   => $cle['apercu'],
            'cle_api_creee_at' => now(),
        ]);
        $partenaire->save();

        $this->journaliser($request, 'partenaire_retrait_cree', "Partenaire {$partenaire->sigle}", 'info', [
            'partenaire_id' => $partenaire->id,
            'nom'           => $partenaire->nom,
        ]);

        return response()->json([
            'partenaire'    => $this->presenter($partenaire->loadCount('agents')),
            // ⚠️ Unique apparition de la clé en clair.
            'cle_api'       => $cle['cle'],
            'avertissement' => 'Cette clé ne sera plus jamais affichée. Transmettez-la au partenaire par un canal sûr.',
        ], 201);
    }

    /** PATCH /api/admin/partenaires-retrait/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);
        $projectId = $request->user()->project_id;

        $partenaire = TondoPartenaireRetrait::where('project_id', $projectId)->withCount('agents')->find($id);
        if (! $partenaire) {
            return response()->json(['message' => 'Partenaire introuvable.'], 404);
        }

        if ($request->has('sigle')) {
            $request->merge(['sigle' => RetraitAgents::normaliserSigle($request->input('sigle'))]);
        }
        $data = $request->validate($this->regles($projectId, $partenaire->id, partiel: true));

        // L'identifiant de chaque agent encode le sigle et reste figé : le
        // changer ferait coexister « ECKTPE020 » et « ECOTPE021 » chez le même
        // partenaire.
        if (isset($data['sigle']) && $data['sigle'] !== $partenaire->sigle && $partenaire->agents_count > 0) {
            return response()->json([
                'message' => "Le sigle ne peut plus changer : {$partenaire->agents_count} agent(s) l'utilisent déjà dans leur identifiant.",
            ], 409);
        }

        $avant = $partenaire->only(array_keys($data));
        $partenaire->fill($data);
        $partenaire->save();

        $this->journaliser(
            $request,
            'partenaire_retrait_modifie',
            "Partenaire {$partenaire->sigle}",
            // Désactiver un partenaire bloque tous ses agents d'un coup.
            array_key_exists('actif', $data) && $data['actif'] === false ? 'warning' : 'info',
            ['partenaire_id' => $partenaire->id, 'avant' => $avant, 'apres' => $data],
        );

        return response()->json(['partenaire' => $this->presenter($partenaire)]);
    }

    /** DELETE /api/admin/partenaires-retrait/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);

        $partenaire = TondoPartenaireRetrait::where('project_id', $request->user()->project_id)
            ->withCount('agents')->find($id);
        if (! $partenaire) {
            return response()->json(['message' => 'Partenaire introuvable.'], 404);
        }

        if ($partenaire->agents_count > 0) {
            return response()->json([
                'message' => "Suppression impossible : {$partenaire->agents_count} agent(s) sont rattachés à ce partenaire. Désactivez-le plutôt.",
            ], 409);
        }

        $partenaire->delete();

        $this->journaliser($request, 'partenaire_retrait_supprime', "Partenaire {$partenaire->sigle}", 'warning', [
            'partenaire_id' => $partenaire->id,
            'nom'           => $partenaire->nom,
        ]);

        return response()->json(['supprime' => true]);
    }

    /**
     * POST /api/admin/partenaires-retrait/{id}/cle
     *
     * Réémet la clé. L'ancienne cesse de fonctionner IMMÉDIATEMENT : c'est la
     * procédure de révocation, et une fenêtre de grâce ne révoquerait rien.
     */
    public function rotationCle(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);

        $partenaire = TondoPartenaireRetrait::where('project_id', $request->user()->project_id)
            ->withCount('agents')->find($id);
        if (! $partenaire) {
            return response()->json(['message' => 'Partenaire introuvable.'], 404);
        }

        $cle = RetraitAgents::genererCleApi($partenaire->sigle);

        $partenaire->cle_api_hash     = $cle['hash'];
        $partenaire->cle_api_apercu   = $cle['apercu'];
        $partenaire->cle_api_creee_at = now();
        $partenaire->save();

        $this->journaliser($request, 'partenaire_retrait_cle_reemise', "Partenaire {$partenaire->sigle}", 'warning', [
            'partenaire_id' => $partenaire->id,
        ]);

        return response()->json([
            'partenaire'    => $this->presenter($partenaire),
            'cle_api'       => $cle['cle'],
            'avertissement' => 'L\'ancienne clé ne fonctionne plus. Cette nouvelle clé ne sera plus jamais affichée.',
        ]);
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function regles(string $projectId, ?string $ignorerId = null, bool $partiel = false): array
    {
        $requis = $partiel ? 'sometimes' : 'required';
        $table  = project_table('partenaires_retrait');

        return [
            'nom'                   => [$requis, 'string', 'max:120',
                // Unicité sans tenir compte de la casse, comme l'index en base :
                // un message clair plutôt qu'une erreur 500.
                function (string $attribut, mixed $valeur, \Closure $echec) use ($table, $projectId, $ignorerId) {
                    $existe = DB::table($table)
                        ->where('project_id', $projectId)
                        ->whereRaw('lower(nom) = ?', [mb_strtolower(trim((string) $valeur))])
                        ->when($ignorerId, fn ($q) => $q->where('id', '!=', $ignorerId))
                        ->exists();
                    if ($existe) {
                        $echec('Un partenaire porte déjà ce nom.');
                    }
                }],
            'sigle'                 => [$requis, 'string', 'regex:' . RetraitAgents::FORMAT_SIGLE,
                Rule::unique($table, 'sigle')->where('project_id', $projectId)->ignore($ignorerId)],
            'actif'                 => ['sometimes', 'boolean'],
            'contact_nom'           => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact_telephone'     => ['sometimes', 'nullable', 'string', 'max:20'],
            'contact_email'         => ['sometimes', 'nullable', 'email', 'max:160'],
            'reglement_coordonnees' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, mixed> */
    private function presenter(TondoPartenaireRetrait $p): array
    {
        return [
            'id'                    => $p->id,
            'nom'                   => $p->nom,
            'sigle'                 => $p->sigle,
            'actif'                 => $p->actif,
            'contact_nom'           => $p->contact_nom,
            'contact_telephone'     => $p->contact_telephone,
            'contact_email'         => $p->contact_email,
            'reglement_coordonnees' => $p->reglement_coordonnees,
            'cle_api_apercu'        => $p->cle_api_apercu,
            'cle_api_creee_at'      => $p->cle_api_creee_at?->toIso8601String(),
            'nb_agents'             => (int) ($p->agents_count ?? 0),
            'sigle_fige'            => (int) ($p->agents_count ?? 0) > 0,
            'created_at'            => $p->created_at?->toIso8601String(),
        ];
    }
}
