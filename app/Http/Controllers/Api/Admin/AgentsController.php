<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\GereRetraitAgents;
use App\Http\Controllers\Controller;
use App\Models\TondoAgent;
use App\Models\TondoPartenaireRetrait;
use App\Models\TondoSupportRetrait;
use App\Support\RetraitAgents;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Agents de retrait en espèces.
 *
 * Un agent est rattaché à un partenaire ET à un support, et reçoit à sa
 * création un identifiant qui les encode (« ECKTPE020 ») et un PIN aléatoire.
 * Ce PIN n'est montré qu'UNE fois, à l'admin qui le transmet ; l'agent le
 * remplace à sa première connexion.
 *
 * Ni le partenaire ni le support ne se modifient ensuite : l'identifiant les
 * porte, et il figure dans les SMS déjà envoyés. Un agent qui change de
 * partenaire ou de canal est un nouvel agent.
 */
class AgentsController extends Controller
{
    use GereRetraitAgents;

    /**
     * GET /api/admin/agents
     *
     * Filtres facultatifs : `partenaire_id`, `support_id`, `statut`,
     * `q` (identifiant, nom ou ville).
     */
    public function index(Request $request): JsonResponse
    {
        $requete = TondoAgent::with(['partenaire', 'support'])
            ->where('project_id', $request->user()->project_id);

        foreach (['partenaire_id', 'support_id'] as $filtre) {
            if (Str::isUuid((string) $request->query($filtre))) {
                $requete->where($filtre, $request->query($filtre));
            }
        }
        if (in_array($request->query('statut'), RetraitAgents::STATUTS, true)) {
            $requete->where('statut', $request->query('statut'));
        }
        if ($terme = trim((string) $request->query('q', ''))) {
            $motif = '%' . $terme . '%';
            $requete->where(fn ($q) => $q
                ->where('identifiant', 'ilike', $motif)
                ->orWhere('nom', 'ilike', $motif)
                ->orWhere('ville', 'ilike', $motif));
        }

        $agents  = $requete->orderByDesc('created_at')->limit(500)->get();
        $retraits = TondoAgent::nombresRetraits($agents->pluck('id'));

        return response()->json([
            'agents' => $agents->map(fn (TondoAgent $a) => $this->presenter($a, $retraits[$a->id] ?? 0)),
        ]);
    }

    /** POST /api/admin/agents */
    public function store(Request $request): JsonResponse
    {
        $this->exigerSuperAdmin($request);
        $projectId = $request->user()->project_id;

        $data = $request->validate([
            'partenaire_id'           => ['required', 'uuid'],
            'support_id'              => ['required', 'uuid'],
            'nom'                     => ['required', 'string', 'max:120'],
            'telephone'               => ['nullable', 'string', 'max:20'],
            'ville'                   => ['nullable', 'string', 'max:80'],
            'quartier'                => ['nullable', 'string', 'max:80'],
            'plafond_operation_fcfa'  => ['nullable', 'integer', 'min:1000', 'max:5000000'],
            'plafond_journalier_fcfa' => ['nullable', 'integer', 'min:1000', 'max:50000000'],
        ]);

        $partenaire = TondoPartenaireRetrait::where('project_id', $projectId)->find($data['partenaire_id']);
        $support    = TondoSupportRetrait::where('project_id', $projectId)->find($data['support_id']);

        if (! $partenaire || ! $support) {
            return response()->json(['message' => 'Partenaire ou support introuvable.'], 422);
        }
        // Un agent créé sur un partenaire ou un support désactivé naîtrait
        // bloqué, sans que rien dans le formulaire ne l'ait laissé paraître.
        if (! $partenaire->actif || ! $support->actif) {
            return response()->json([
                'message' => 'Le partenaire ou le support choisi est désactivé. Réactivez-le avant de créer un agent.',
            ], 422);
        }
        if ($erreur = $this->erreurLieu($support, $data['ville'] ?? null, $data['quartier'] ?? null)) {
            return response()->json(['message' => $erreur, 'errors' => ['ville' => [$erreur]]], 422);
        }

        $plafondOperation  = $data['plafond_operation_fcfa']  ?? 200000;
        $plafondJournalier = $data['plafond_journalier_fcfa'] ?? max(1000000, $plafondOperation);
        if ($erreur = $this->erreurPlafonds($plafondOperation, $plafondJournalier)) {
            return response()->json(['message' => $erreur, 'errors' => ['plafond_journalier_fcfa' => [$erreur]]], 422);
        }

        $pin = RetraitAgents::genererPin();

        $agent = DB::transaction(function () use ($projectId, $partenaire, $support, $data, $pin, $plafondOperation, $plafondJournalier) {
            // Numéro tiré d'un compteur par couple partenaire × support, par un
            // UPSERT atomique : deux créations simultanées obtiennent deux
            // numéros distincts, et un numéro n'est jamais réattribué.
            $table  = project_table('agents_compteurs');
            $numero = (int) DB::selectOne(
                "INSERT INTO {$table} (project_id, partenaire_id, support_id, dernier_numero, updated_at)
                 VALUES (?, ?, ?, 1, now())
                 ON CONFLICT (partenaire_id, support_id)
                 DO UPDATE SET dernier_numero = {$table}.dernier_numero + 1, updated_at = now()
                 RETURNING dernier_numero",
                [$projectId, $partenaire->id, $support->id],
            )->dernier_numero;

            $agent = new TondoAgent();
            // id généré en PHP : Eloquent ne relit pas la valeur du DEFAULT.
            $agent->id = (string) Str::uuid();
            $agent->fill([
                'project_id'              => $projectId,
                'partenaire_id'           => $partenaire->id,
                'support_id'              => $support->id,
                'numero'                  => $numero,
                'identifiant'             => RetraitAgents::composerIdentifiant($partenaire->sigle, $support->sigle, $numero),
                'nom'                     => $data['nom'],
                'telephone'               => $data['telephone'] ?? null,
                'ville'                   => $data['ville'] ?? null,
                'quartier'                => $data['quartier'] ?? null,
                'statut'                  => 'actif',
                'pin_hash'                => Hash::make($pin),
                'pin_doit_changer'        => true,
                'plafond_operation_fcfa'  => $plafondOperation,
                'plafond_journalier_fcfa' => $plafondJournalier,
            ]);
            $agent->save();

            return $agent;
        });

        // Le PIN n'entre JAMAIS dans le journal : quiconque lit les journaux
        // pourrait sinon se connecter à la place de l'agent.
        $this->journaliser($request, 'agent_cree', "Agent {$agent->identifiant}", 'info', [
            'agent_id'      => $agent->id,
            'partenaire_id' => $partenaire->id,
            'support_id'    => $support->id,
        ]);

        return response()->json([
            'agent'         => $this->presenter($agent->load(['partenaire', 'support'])),
            // ⚠️ Unique apparition du PIN en clair.
            'pin'           => $pin,
            'avertissement' => 'Ce PIN ne sera plus jamais affiché. Transmettez-le à l\'agent : il devra le changer à sa première connexion.',
        ], 201);
    }

    /**
     * PATCH /api/admin/agents/{id}
     *
     * Partenaire et support refusés explicitement plutôt qu'ignorés : un
     * formulaire qui les enverrait croirait avoir réussi.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);

        $agent = $this->trouver($request, $id);
        if (! $agent) {
            return response()->json(['message' => 'Agent introuvable.'], 404);
        }

        if ($request->hasAny(['partenaire_id', 'support_id', 'identifiant', 'numero'])) {
            return response()->json([
                'message' => 'Le partenaire, le support et l\'identifiant d\'un agent ne se modifient pas. Créez un nouvel agent.',
            ], 422);
        }

        $data = $request->validate([
            'nom'                     => ['sometimes', 'string', 'max:120'],
            'telephone'               => ['sometimes', 'nullable', 'string', 'max:20'],
            'ville'                   => ['sometimes', 'nullable', 'string', 'max:80'],
            'quartier'                => ['sometimes', 'nullable', 'string', 'max:80'],
            'plafond_operation_fcfa'  => ['sometimes', 'integer', 'min:1000', 'max:5000000'],
            'plafond_journalier_fcfa' => ['sometimes', 'integer', 'min:1000', 'max:50000000'],
        ]);

        // Cohérence évaluée sur l'état FINAL, pas sur les seuls champs envoyés :
        // baisser le plafond journalier sous le plafond par opération existant
        // doit être refusé même si ce dernier n'est pas dans la requête.
        $apres = array_merge($agent->only([
            'ville', 'quartier', 'plafond_operation_fcfa', 'plafond_journalier_fcfa',
        ]), $data);

        if ($erreur = $this->erreurLieu($agent->support, $apres['ville'], $apres['quartier'])) {
            return response()->json(['message' => $erreur, 'errors' => ['ville' => [$erreur]]], 422);
        }
        if ($erreur = $this->erreurPlafonds($apres['plafond_operation_fcfa'], $apres['plafond_journalier_fcfa'])) {
            return response()->json(['message' => $erreur, 'errors' => ['plafond_journalier_fcfa' => [$erreur]]], 422);
        }

        $avant = $agent->only(array_keys($data));
        $agent->fill($data);
        $agent->save();

        $this->journaliser($request, 'agent_modifie', "Agent {$agent->identifiant}", 'info', [
            'agent_id' => $agent->id,
            'avant'    => $avant,
            'apres'    => $data,
        ]);

        return response()->json(['agent' => $this->presenter($agent)]);
    }

    /**
     * POST /api/admin/agents/{id}/statut
     * Body : { statut: 'actif'|'suspendu', motif?: string }
     */
    public function statut(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);

        $agent = $this->trouver($request, $id);
        if (! $agent) {
            return response()->json(['message' => 'Agent introuvable.'], 404);
        }

        $data = $request->validate([
            'statut' => ['required', 'string', 'in:' . implode(',', RetraitAgents::STATUTS)],
            'motif'  => ['nullable', 'string', 'max:300'],
        ]);

        $agent->statut = $data['statut'];
        // Un motif conservé après réactivation laisserait croire à une
        // suspension toujours en cours.
        $agent->motif_suspension = $data['statut'] === 'suspendu' ? ($data['motif'] ?? null) : null;
        $agent->save();

        $this->journaliser(
            $request,
            $data['statut'] === 'suspendu' ? 'agent_suspendu' : 'agent_reactive',
            "Agent {$agent->identifiant}",
            $data['statut'] === 'suspendu' ? 'warning' : 'info',
            ['agent_id' => $agent->id, 'motif' => $data['motif'] ?? null],
        );

        return response()->json(['agent' => $this->presenter($agent)]);
    }

    /**
     * POST /api/admin/agents/{id}/pin
     *
     * Réinitialise le PIN : nouveau PIN aléatoire, à changer à la prochaine
     * connexion, compteur d'échecs remis à zéro et verrou levé. C'est la seule
     * façon de débloquer un agent verrouillé — volontairement une action
     * d'admin, tracée, et non un délai qui expirerait tout seul.
     */
    public function reinitialiserPin(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);

        $agent = $this->trouver($request, $id);
        if (! $agent) {
            return response()->json(['message' => 'Agent introuvable.'], 404);
        }

        $pin         = RetraitAgents::genererPin();
        $etaitVerrou = $agent->estVerrouille();

        $agent->pin_hash                = Hash::make($pin);
        $agent->pin_doit_changer        = true;
        $agent->pin_tentatives_echouees = 0;
        $agent->pin_verrouille_at       = null;
        $agent->pin_modifie_at          = null;
        $agent->save();

        $this->journaliser($request, 'agent_pin_reinitialise', "Agent {$agent->identifiant}", 'warning', [
            'agent_id'       => $agent->id,
            'etait_verrouille' => $etaitVerrou,
        ]);

        return response()->json([
            'agent'         => $this->presenter($agent),
            'pin'           => $pin,
            'avertissement' => 'L\'ancien PIN ne fonctionne plus. Ce nouveau PIN ne sera plus jamais affiché ; l\'agent devra le changer à sa prochaine connexion.',
        ]);
    }

    /**
     * DELETE /api/admin/agents/{id}
     *
     * Possible tant qu'AUCUN retrait n'est passé par l'agent. Au-delà, il porte
     * un historique d'espèces remises — des montants, des cagnottes débitées,
     * de quoi instruire un litige — et se suspend au lieu de disparaître.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);

        $agent = $this->trouver($request, $id);
        if (! $agent) {
            return response()->json(['message' => 'Agent introuvable.'], 404);
        }

        $refus = "Suppression impossible : des retraits sont passés par {$agent->identifiant}. Suspendez-le plutôt.";

        if ((TondoAgent::nombresRetraits([$agent->id])[$agent->id] ?? 0) > 0) {
            return response()->json(['message' => $refus], 409);
        }

        try {
            $agent->delete();
        } catch (QueryException $e) {
            // Un retrait enregistré entre le contrôle et la suppression : la clé
            // étrangère RESTRICT refuse, et c'est exactement ce qu'on veut.
            return response()->json(['message' => $refus], 409);
        }

        $this->journaliser($request, 'agent_supprime', "Agent {$agent->identifiant}", 'warning', [
            'agent_id'      => $agent->id,
            'identifiant'   => $agent->identifiant,
            'partenaire_id' => $agent->partenaire_id,
            'support_id'    => $agent->support_id,
        ]);

        return response()->json(['supprime' => true]);
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    private function trouver(Request $request, string $id): ?TondoAgent
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return TondoAgent::with(['partenaire', 'support'])
            ->where('project_id', $request->user()->project_id)
            ->find($id);
    }

    /** Ville et quartier obligatoires sur un support qui a un lieu physique. */
    private function erreurLieu(?TondoSupportRetrait $support, ?string $ville, ?string $quartier): ?string
    {
        if ($support && $support->necessite_lieu && (trim((string) $ville) === '' || trim((string) $quartier) === '')) {
            return "Le support « {$support->libelle} » a un lieu physique : la ville et le quartier sont obligatoires.";
        }

        return null;
    }

    /**
     * Un plafond journalier inférieur au plafond par opération rendrait ce
     * dernier inatteignable sans que rien ne le signale.
     */
    private function erreurPlafonds(int $operation, int $journalier): ?string
    {
        return $journalier < $operation
            ? 'Le plafond journalier ne peut pas être inférieur au plafond par opération.'
            : null;
    }

    /**
     * Représentation d'un agent — jamais le PIN ni son empreinte.
     *
     * @return array<string, mixed>
     */
    private function presenter(TondoAgent $a, ?int $nbRetraits = null): array
    {
        // Les écritures unitaires ne passent pas le compte : on le lit ici.
        $nbRetraits ??= TondoAgent::nombresRetraits([$a->id])[$a->id] ?? 0;

        return [
            'id'                      => $a->id,
            'identifiant'             => $a->identifiant,
            'nom'                     => $a->nom,
            'telephone'               => $a->telephone,
            'ville'                   => $a->ville,
            'quartier'                => $a->quartier,
            'statut'                  => $a->statut,
            'motif_suspension'        => $a->motif_suspension,
            'partenaire'              => $a->partenaire ? [
                'id' => $a->partenaire->id, 'nom' => $a->partenaire->nom,
                'sigle' => $a->partenaire->sigle, 'actif' => $a->partenaire->actif,
            ] : null,
            'support'                 => $a->support ? [
                'id' => $a->support->id, 'libelle' => $a->support->libelle,
                'sigle' => $a->support->sigle, 'actif' => $a->support->actif,
            ] : null,
            'pin_doit_changer'        => $a->pin_doit_changer,
            'pin_verrouille'          => $a->estVerrouille(),
            'pin_tentatives_echouees' => $a->pin_tentatives_echouees,
            'plafond_operation_fcfa'  => $a->plafond_operation_fcfa,
            'plafond_journalier_fcfa' => $a->plafond_journalier_fcfa,
            // Ce qui empêche l'agent d'opérer MAINTENANT, toutes causes
            // confondues — statut, verrou, partenaire ou support désactivé.
            'motif_blocage'           => $a->motifBlocage(),
            'nb_retraits'             => $nbRetraits,
            // Supprimable tant qu'aucune espèce n'a été remise par cet agent.
            'supprimable'             => $nbRetraits === 0,
            'derniere_connexion_at'   => $a->derniere_connexion_at?->toIso8601String(),
            'derniere_activite_at'    => $a->derniere_activite_at?->toIso8601String(),
            'created_at'              => $a->created_at?->toIso8601String(),
        ];
    }
}
