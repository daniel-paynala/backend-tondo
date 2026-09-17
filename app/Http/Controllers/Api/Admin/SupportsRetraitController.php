<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\GereRetraitAgents;
use App\Http\Controllers\Controller;
use App\Models\TondoAgent;
use App\Models\TondoSupportRetrait;
use App\Support\RetraitAgents;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Paramètres — supports de retrait (TPE, guichet, boutique, USSD…).
 *
 * Deux règles :
 *  – le sigle ne se modifie plus dès qu'un agent l'utilise : il est dans
 *    leurs identifiants ;
 *  – le support se supprime tant qu'AUCUN retrait n'est passé par lui, ses
 *    agents avec. Au-delà, il porte un historique d'espèces remises et se
 *    désactive au lieu de disparaître.
 */
class SupportsRetraitController extends Controller
{
    use GereRetraitAgents;

    /** GET /api/admin/supports-retrait */
    public function index(Request $request): JsonResponse
    {
        $supports = TondoSupportRetrait::where('project_id', $request->user()->project_id)
            ->withCount('agents')
            ->orderBy('libelle')
            ->get();

        $retraits = $this->nombresRetraits($supports->pluck('id')->all());

        return response()->json([
            'supports' => $supports->map(fn (TondoSupportRetrait $s) => $this->presenter($s, $retraits[$s->id] ?? 0)),
        ]);
    }

    /** POST /api/admin/supports-retrait */
    public function store(Request $request): JsonResponse
    {
        $this->exigerSuperAdmin($request);
        $projectId = $request->user()->project_id;

        $request->merge(['sigle' => RetraitAgents::normaliserSigle($request->input('sigle'))]);
        $data = $request->validate($this->regles($projectId));

        $support = new TondoSupportRetrait();
        // id généré en PHP : le DEFAULT de la colonne crée bien la ligne, mais
        // Eloquent ne relit pas la valeur quand la clé n'est pas incrémentée.
        $support->id = (string) Str::uuid();
        $support->fill($data + ['project_id' => $projectId]);
        $support->save();

        $this->journaliser($request, 'support_retrait_cree', "Support {$support->sigle}", 'info', [
            'support_id' => $support->id,
            'libelle'    => $support->libelle,
        ]);

        return response()->json(['support' => $this->presenter($support->loadCount('agents'))], 201);
    }

    /** PATCH /api/admin/supports-retrait/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);
        $projectId = $request->user()->project_id;

        $support = TondoSupportRetrait::where('project_id', $projectId)->withCount('agents')->find($id);
        if (! $support) {
            return response()->json(['message' => 'Support introuvable.'], 404);
        }

        if ($request->has('sigle')) {
            $request->merge(['sigle' => RetraitAgents::normaliserSigle($request->input('sigle'))]);
        }
        $data = $request->validate($this->regles($projectId, $support->id, partiel: true));

        // L'identifiant des agents encode le sigle et reste figé : changer le
        // sigle d'un support utilisé ferait coexister « ECKTPE020 » et
        // « ECKTRM021 » pour le même canal.
        if (isset($data['sigle']) && $data['sigle'] !== $support->sigle && $support->agents_count > 0) {
            return response()->json([
                'message' => "Le sigle ne peut plus changer : {$support->agents_count} agent(s) l'utilisent déjà dans leur identifiant.",
            ], 409);
        }

        $avant = $support->only(array_keys($data));
        $support->fill($data);
        $support->save();

        $this->journaliser($request, 'support_retrait_modifie', "Support {$support->sigle}", 'info', [
            'support_id' => $support->id,
            'avant'      => $avant,
            'apres'      => $data,
        ]);

        return response()->json(['support' => $this->presenter($support)]);
    }

    /**
     * DELETE /api/admin/supports-retrait/{id}
     *
     * Possible tant qu'aucun retrait n'est passé par le support. Ses agents
     * sont supprimés avec lui : n'ayant jamais servi, ils ne portent aucun
     * historique à préserver.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);

        $support = TondoSupportRetrait::where('project_id', $request->user()->project_id)
            ->withCount('agents')->find($id);
        if (! $support) {
            return response()->json(['message' => 'Support introuvable.'], 404);
        }

        $refus = "Suppression impossible : des retraits sont passés par le support « {$support->libelle} ». Désactivez-le plutôt.";

        if (($this->nombresRetraits([$support->id])[$support->id] ?? 0) > 0) {
            return response()->json(['message' => $refus], 409);
        }

        $identifiants = TondoAgent::where('support_id', $support->id)->orderBy('identifiant')->pluck('identifiant')->all();

        try {
            DB::transaction(function () use ($support) {
                // Agents d'abord : la clé étrangère agents → supports est en
                // RESTRICT. Les compteurs partent en cascade avec le support.
                TondoAgent::where('support_id', $support->id)->delete();
                $support->delete();
            });
        } catch (QueryException $e) {
            // Un retrait enregistré entre le contrôle et la suppression : la clé
            // étrangère retraits → agents refuse, et la transaction est annulée.
            return response()->json(['message' => $refus], 409);
        }

        // Les identifiants supprimés sont conservés au journal : ce sont eux
        // qu'on retrouverait dans une conversation avec un partenaire.
        $this->journaliser($request, 'support_retrait_supprime', "Support {$support->sigle}", 'warning', [
            'support_id'        => $support->id,
            'libelle'           => $support->libelle,
            'agents_supprimes'  => $identifiants,
        ]);

        return response()->json(['supprime' => true, 'agents_supprimes' => count($identifiants)]);
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function regles(string $projectId, ?string $ignorerId = null, bool $partiel = false): array
    {
        $requis = $partiel ? 'sometimes' : 'required';
        $table  = project_table('supports_retrait');

        return [
            'libelle'        => [$requis, 'string', 'max:80',
                // La base impose l'unicité sans tenir compte de la casse. Le
                // vérifier ici donne un message clair au lieu d'une erreur 500
                // levée par l'index.
                function (string $attribut, mixed $valeur, \Closure $echec) use ($table, $projectId, $ignorerId) {
                    $existe = \Illuminate\Support\Facades\DB::table($table)
                        ->where('project_id', $projectId)
                        ->whereRaw('lower(libelle) = ?', [mb_strtolower(trim((string) $valeur))])
                        ->when($ignorerId, fn ($q) => $q->where('id', '!=', $ignorerId))
                        ->exists();
                    if ($existe) {
                        $echec('Un support porte déjà ce libellé.');
                    }
                }],
            'sigle'          => [$requis, 'string', 'regex:' . RetraitAgents::FORMAT_SIGLE,
                Rule::unique($table, 'sigle')->where('project_id', $projectId)->ignore($ignorerId)],
            'description'    => ['sometimes', 'nullable', 'string', 'max:300'],
            'necessite_lieu' => ['sometimes', 'boolean'],
            'actif'          => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Nombre de retraits passés par les agents de chacun des supports donnés.
     *
     * @param  array<int, string> $supportIds
     * @return array<string, int>  support_id => nombre de retraits
     */
    private function nombresRetraits(array $supportIds): array
    {
        if ($supportIds === []) {
            return [];
        }

        return DB::table(project_table('retraits_especes') . ' as r')
            ->join(project_table('agents') . ' as a', 'a.id', '=', 'r.agent_id')
            ->whereIn('a.support_id', $supportIds)
            ->selectRaw('a.support_id, count(*) as n')
            ->groupBy('a.support_id')
            ->pluck('n', 'support_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return array<string, mixed> */
    private function presenter(TondoSupportRetrait $s, ?int $nbRetraits = null): array
    {
        $nbRetraits ??= $this->nombresRetraits([$s->id])[$s->id] ?? 0;

        return [
            'id'             => $s->id,
            'libelle'        => $s->libelle,
            'sigle'          => $s->sigle,
            'description'    => $s->description,
            'necessite_lieu' => $s->necessite_lieu,
            'actif'          => $s->actif,
            'nb_agents'      => (int) ($s->agents_count ?? 0),
            // Indique à l'interface si le sigle est encore modifiable.
            'sigle_fige'     => (int) ($s->agents_count ?? 0) > 0,
            'nb_retraits'    => $nbRetraits,
            // Supprimable tant qu'aucune espèce n'a été remise par ce canal.
            'supprimable'    => $nbRetraits === 0,
            'created_at'     => $s->created_at?->toIso8601String(),
        ];
    }
}
