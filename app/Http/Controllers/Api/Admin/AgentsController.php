<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoAgent;
use App\Support\TypesAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Administration des agents de retrait en espèces.
 *
 * Un agent remet des BILLETS. Un virement erroné se conteste ; des espèces
 * remises à tort sont perdues. Toute la gestion est donc plus stricte que
 * celle d'une ressource ordinaire :
 *
 *  – **réservée aux super admins** : habiliter un tiers à distribuer de
 *    l'argent liquide n'est pas une opération de gestion courante ;
 *  – **entièrement journalisée** : création, suspension, réémission de clé et
 *    changement de plafond laissent une trace nominative ;
 *  – **la clé n'est montrée qu'une fois** : seule son empreinte est conservée,
 *    rien ne permet de la retrouver ensuite.
 */
class AgentsController extends Controller
{
    /** Bornes de tentatives pour tirer un code libre. */
    private const TENTATIVES_CODE = 12;

    /**
     * GET /api/admin/agents
     *
     * Filtres facultatifs : `type`, `statut`, `q` (code, nom ou ville).
     */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;

        $requete = TondoAgent::where('project_id', $projectId);

        if (TypesAgent::typeValide($request->query('type'))) {
            $requete->where('type', $request->query('type'));
        }
        if (TypesAgent::statutValide($request->query('statut'))) {
            $requete->where('statut', $request->query('statut'));
        }
        if ($terme = trim((string) $request->query('q', ''))) {
            $motif = '%' . $terme . '%';
            $requete->where(function ($q) use ($motif) {
                $q->where('code', 'ilike', $motif)
                  ->orWhere('nom', 'ilike', $motif)
                  ->orWhere('ville', 'ilike', $motif);
            });
        }

        return response()->json([
            'agents' => $requete->orderByDesc('created_at')->limit(200)->get()
                ->map(fn (TondoAgent $a) => $this->presenter($a)),
        ]);
    }

    /**
     * POST /api/admin/agents
     *
     * Crée l'agent, tire son code public et émet sa première clé.
     * La clé figure dans CETTE réponse et nulle part ailleurs.
     */
    public function store(Request $request): JsonResponse
    {
        $this->exigerSuperAdmin($request);

        $data = $request->validate([
            'nom'                  => ['required', 'string', 'max:120'],
            'type'                 => ['required', 'string', 'in:' . implode(',', TypesAgent::TYPES)],
            'ville'                => ['nullable', 'string', 'max:80'],
            'quartier'             => ['nullable', 'string', 'max:80'],
            'telephone'            => ['nullable', 'string', 'max:20'],
            'plafond_retrait_fcfa' => ['nullable', 'integer', 'min:1000', 'max:5000000'],
        ]);

        $projectId = $request->user()->project_id;
        $code      = $this->tirerCodeLibre($projectId);

        if ($code === null) {
            // Douze tirages infructueux ne relèvent plus du hasard : soit la
            // table est saturée, soit quelque chose ne va pas. On refuse
            // plutôt que de boucler indéfiniment.
            return response()->json([
                'message' => 'Impossible de générer un code agent disponible. Contactez la technique.',
            ], 503);
        }

        $cle = TypesAgent::genererCleApi($code);

        $agent = new TondoAgent();
        $agent->fill([
            'project_id'           => $projectId,
            'code'                 => $code,
            'nom'                  => $data['nom'],
            'type'                 => $data['type'],
            'statut'               => 'actif',
            'ville'                => $data['ville'] ?? null,
            'quartier'             => $data['quartier'] ?? null,
            'telephone'            => $data['telephone'] ?? null,
            'plafond_retrait_fcfa' => $data['plafond_retrait_fcfa'] ?? 200000,
            'cle_api_hash'         => $cle['hash'],
            'cle_api_apercu'       => $cle['apercu'],
            'cle_api_creee_at'     => now(),
        ]);
        $agent->save();

        $this->journaliser($request, 'agent_cree', "Agent {$code}", 'info', [
            'agent_id' => $agent->id,
            'type'     => $agent->type,
            'plafond'  => $agent->plafond_retrait_fcfa,
        ]);

        return response()->json([
            'agent' => $this->presenter($agent),
            // ⚠️ Unique apparition de la clé en clair, de toute sa vie.
            'cle_api' => $cle['cle'],
            'avertissement' => 'Cette clé ne sera plus jamais affichée. Transmettez-la à l\'exploitant et conservez-en une copie sûre.',
        ], 201);
    }

    /**
     * PATCH /api/admin/agents/{id}
     *
     * Le `code` n'est PAS modifiable : il est affiché au comptoir et repris
     * dans les SMS déjà envoyés. Le changer invaliderait ce que des
     * bénéficiaires ont sous les yeux.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);

        $agent = $this->trouver($request, $id);
        if (! $agent) {
            return response()->json(['message' => 'Agent introuvable.'], 404);
        }

        $data = $request->validate([
            'nom'                  => ['sometimes', 'string', 'max:120'],
            'type'                 => ['sometimes', 'string', 'in:' . implode(',', TypesAgent::TYPES)],
            'ville'                => ['sometimes', 'nullable', 'string', 'max:80'],
            'quartier'             => ['sometimes', 'nullable', 'string', 'max:80'],
            'telephone'            => ['sometimes', 'nullable', 'string', 'max:20'],
            'plafond_retrait_fcfa' => ['sometimes', 'integer', 'min:1000', 'max:5000000'],
        ]);

        $avant = $agent->only(array_keys($data));
        $agent->fill($data);
        $agent->save();

        $this->journaliser($request, 'agent_modifie', "Agent {$agent->code}", 'info', [
            'agent_id' => $agent->id,
            'avant'    => $avant,
            'apres'    => $data,
        ]);

        return response()->json(['agent' => $this->presenter($agent)]);
    }

    /**
     * POST /api/admin/agents/{id}/statut
     * Body : { statut: 'actif'|'suspendu', motif?: string }
     *
     * La suspension prend effet à l'appel suivant : l'authentification relit
     * le statut à chaque requête, sans cache.
     */
    public function statut(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);

        $agent = $this->trouver($request, $id);
        if (! $agent) {
            return response()->json(['message' => 'Agent introuvable.'], 404);
        }

        $data = $request->validate([
            'statut' => ['required', 'string', 'in:' . implode(',', TypesAgent::STATUTS)],
            'motif'  => ['nullable', 'string', 'max:300'],
        ]);

        $agent->statut = $data['statut'];
        // Le motif n'a de sens que sur une suspension ; le conserver après
        // réactivation laisserait croire à une suspension toujours en cours.
        $agent->motif_suspension = $data['statut'] === 'suspendu' ? ($data['motif'] ?? null) : null;
        $agent->save();

        $this->journaliser(
            $request,
            $data['statut'] === 'suspendu' ? 'agent_suspendu' : 'agent_reactive',
            "Agent {$agent->code}",
            $data['statut'] === 'suspendu' ? 'warning' : 'info',
            ['agent_id' => $agent->id, 'motif' => $data['motif'] ?? null],
        );

        return response()->json(['agent' => $this->presenter($agent)]);
    }

    /**
     * POST /api/admin/agents/{id}/cle
     *
     * Réémet la clé. L'ancienne cesse de fonctionner IMMÉDIATEMENT : c'est la
     * procédure de révocation, et une révocation qui laisserait une fenêtre de
     * grâce ne révoquerait rien.
     */
    public function rotationCle(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);

        $agent = $this->trouver($request, $id);
        if (! $agent) {
            return response()->json(['message' => 'Agent introuvable.'], 404);
        }

        $cle = TypesAgent::genererCleApi($agent->code);

        $agent->cle_api_hash     = $cle['hash'];
        $agent->cle_api_apercu   = $cle['apercu'];
        $agent->cle_api_creee_at = now();
        $agent->save();

        $this->journaliser($request, 'agent_cle_reemise', "Agent {$agent->code}", 'warning', [
            'agent_id' => $agent->id,
        ]);

        return response()->json([
            'agent'         => $this->presenter($agent),
            'cle_api'       => $cle['cle'],
            'avertissement' => 'L\'ancienne clé ne fonctionne plus. Cette nouvelle clé ne sera plus jamais affichée.',
        ]);
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    private function exigerSuperAdmin(Request $request): void
    {
        abort_unless(
            $request->user()->role === 'super_admin',
            403,
            'Action réservée aux super admins.',
        );
    }

    private function trouver(Request $request, string $id): ?TondoAgent
    {
        return TondoAgent::where('project_id', $request->user()->project_id)->find($id);
    }

    /**
     * Tire un code non encore utilisé dans ce projet.
     *
     * Le tirage est aléatoire et non séquentiel : une numérotation croissante
     * publierait le nombre d'agents et l'ordre des recrutements. On vérifie
     * donc l'unicité, plutôt que de la tenir d'une suite.
     */
    private function tirerCodeLibre(string $projectId): ?string
    {
        for ($i = 0; $i < self::TENTATIVES_CODE; $i++) {
            $code = TypesAgent::genererCode();
            $pris = TondoAgent::where('project_id', $projectId)->where('code', $code)->exists();
            if (! $pris) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Forme la représentation publique d'un agent.
     *
     * `cle_api_hash` n'y figure pas : l'empreinte suffit à se faire passer
     * pour l'agent, il n'y a pas de sel à connaître en plus.
     *
     * @return array<string, mixed>
     */
    private function presenter(TondoAgent $a): array
    {
        return [
            'id'                   => $a->id,
            'code'                 => $a->code,
            'nom'                  => $a->nom,
            'type'                 => $a->type,
            'statut'               => $a->statut,
            'motif_suspension'     => $a->motif_suspension,
            'ville'                => $a->ville,
            'quartier'             => $a->quartier,
            'telephone'            => $a->telephone,
            'plafond_retrait_fcfa' => $a->plafond_retrait_fcfa,
            'cle_api_apercu'       => $a->cle_api_apercu,
            'cle_api_creee_at'     => $a->cle_api_creee_at?->toIso8601String(),
            'derniere_activite_at' => $a->derniere_activite_at?->toIso8601String(),
            'operationnel'         => $a->estOperationnel(),
            'created_at'           => $a->created_at?->toIso8601String(),
        ];
    }

    /**
     * Trace l'action dans le journal d'audit.
     *
     * @param  array<string, mixed> $metadonnees
     */
    private function journaliser(
        Request $request,
        string  $action,
        string  $cible,
        string  $niveau,
        array   $metadonnees,
    ): void {
        $admin = $request->user();

        DB::table(project_table('logs'))->insert([
            'id'              => (string) Str::uuid(),
            'project_id'      => $admin->project_id,
            'acteur_admin_id' => $admin->id,
            'acteur_libelle'  => trim(($admin->prenom ?? '') . ' ' . ($admin->nom ?? '')) ?: 'Admin',
            'acteur_role'     => $admin->role,
            'action'          => $action,
            'cible'           => $cible,
            'niveau'          => $niveau,
            'metadonnees'     => json_encode($metadonnees),
            'date'            => now(),
            'created_at'      => now(),
        ]);
    }
}
