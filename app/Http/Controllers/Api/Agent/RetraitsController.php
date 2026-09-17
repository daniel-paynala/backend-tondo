<?php

namespace App\Http\Controllers\Api\Agent;

use App\Exceptions\RetraitImpossible;
use App\Http\Controllers\Controller;
use App\Models\TondoAgent;
use App\Models\TondoCagnotte;
use App\Services\ReceiptService;
use App\Services\RetraitEspecesService;
use App\Support\RetraitEspeces;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Retraits en espèces, vus du terminal de l'agent.
 *
 * Contrat unique pour le logiciel du partenaire : **ne remettre des billets
 * que si `remettre_especes` vaut true.** Tout le reste — message, statut,
 * motif — sert à informer l'agent, jamais à décider.
 */
class RetraitsController extends Controller
{
    public function __construct(private RetraitEspecesService $service) {}

    /**
     * POST /api/agent/retraits
     * En-tête : Idempotency-Key (fourni par le terminal, unique par demande)
     * Body    : { cagnotte: "123456", montant: 150000 }
     */
    public function store(Request $request): JsonResponse
    {
        $cle = trim((string) $request->header('Idempotency-Key'));
        if (! preg_match('/^[A-Za-z0-9_-]{8,100}$/', $cle)) {
            return response()->json([
                'message' => 'En-tête Idempotency-Key obligatoire : 8 à 100 caractères, lettres, chiffres, - et _.',
                'code'    => 'cle_idempotence_invalide',
            ], 400);
        }

        $data = $request->validate([
            'cagnotte' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'montant'  => ['required', 'integer', 'min:1'],
        ]);

        return $this->executer(function () use ($request, $data, $cle) {
            $resultat = $this->service->demander($request->user('agent'), $data['cagnotte'], (int) $data['montant'], $cle);

            return response()->json(
                ['retrait' => $this->presenter($resultat['retrait'], $resultat['titulaire'])],
                // 200 sur un rejeu, 201 à la création : le terminal sait si la
                // demande vient d'être faite ou s'il relit la précédente.
                $resultat['rejoue'] ? 200 : 201,
            );
        });
    }

    /**
     * POST /api/agent/retraits/{reference}/valider
     * Body : { code: "482915" }
     */
    public function valider(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        return $this->executer(fn () => response()->json([
            'retrait' => $this->presenter($this->service->valider($request->user('agent'), $reference, $data['code'])),
        ]));
    }

    /** POST /api/agent/retraits/{reference}/annuler */
    public function annuler(Request $request, string $reference): JsonResponse
    {
        return $this->executer(fn () => response()->json([
            'retrait' => $this->presenter($this->service->annuler($request->user('agent'), $reference)),
        ]));
    }

    /**
     * GET /api/agent/retraits/{reference}
     *
     * À appeler après toute coupure, AVANT de remettre quoi que ce soit : c'est
     * la seule façon de connaître l'issue d'une validation dont la réponse s'est
     * perdue.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        return $this->executer(function () use ($request, $reference) {
            $retrait = $this->service->dossierDeLAgent($request->user('agent'), $reference);

            return response()->json(['retrait' => $this->presenter($retrait)]);
        });
    }

    /**
     * GET /api/agent/retraits?cle={Idempotency-Key}
     *   Retrouve une demande dont la réponse ne s'est jamais rendue au terminal :
     *   il n'en connaît alors pas la référence, seulement sa propre clé.
     *
     * GET /api/agent/retraits?jour=2026-09-17   (par défaut : aujourd'hui)
     *   Caisse du jour de l'agent : ses dossiers et le total remis.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var TondoAgent $agent */
        $agent = $request->user('agent');
        $table = project_table('retraits_especes');

        if ($cle = trim((string) $request->query('cle', ''))) {
            $retrait = DB::table($table)->where('agent_id', $agent->id)->where('cle_idempotence', $cle)->first();
            if (! $retrait) {
                return response()->json(['message' => 'Aucune demande avec cette clé.', 'code' => 'retrait_introuvable'], 404);
            }
            $retrait = $this->service->dossierDeLAgent($agent, $retrait->reference);

            return response()->json(['retrait' => $this->presenter($retrait)]);
        }

        $jour = (string) $request->query('jour', '');
        if ($jour !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour)) {
            return response()->json(['message' => 'Paramètre jour au format AAAA-MM-JJ.', 'code' => 'jour_invalide'], 400);
        }

        $debut = $this->service->debutJournee($jour ?: null);
        $this->service->expirerEchues();

        $dossiers = DB::table($table)
            ->where('agent_id', $agent->id)
            ->where('created_at', '>=', $debut)
            ->where('created_at', '<', $debut->copy()->addDay())
            ->orderByDesc('created_at')
            ->get();

        $valides = $dossiers->where('statut', 'valide');

        return response()->json([
            'jour'                 => $debut->copy()->timezone((string) config('retrait.fuseau'))->toDateString(),
            'nombre_valides'       => $valides->count(),
            'total_remis_fcfa'     => (int) $valides->sum('montant_fcfa'),
            'retraits'             => $dossiers->map(fn ($r) => $this->presenter($r))->values(),
        ]);
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /** Traduit un refus métier en réponse JSON, message ET code stable. */
    private function executer(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (RetraitImpossible $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->codeErreur], $e->statut);
        }
    }

    /**
     * Représentation d'un dossier pour le terminal.
     *
     * @return array<string, mixed>
     */
    private function presenter(object $r, ?string $titulaire = null): array
    {
        $cagnotte  = TondoCagnotte::find($r->cagnotte_id);
        $enAttente = $r->statut === 'en_attente_code';
        $valide    = $r->statut === 'valide';

        // Tant que le dossier attend son code, l'agent a besoin du nom pour
        // contrôler la pièce d'identité — y compris après un code faux ou une
        // reprise après coupure. Le KYC est en cache : aucun appel réseau.
        if ($enAttente && $titulaire === null && $cagnotte) {
            $titulaire = $this->service->titulaire($cagnotte);
        }

        return [
            'reference'        => $r->reference,
            'statut'           => $r->statut,
            // LE seul champ sur lequel le terminal décide de remettre des billets.
            'remettre_especes' => $valide,
            'montant_fcfa'     => (int) $r->montant_fcfa,
            'consigne'         => match ($r->statut) {
                'en_attente_code' => 'Vérifiez la pièce d\'identité, puis saisissez le code reçu par SMS par le titulaire.',
                'valide'          => 'Remettez ' . RetraitEspeces::formaterMontant((int) $r->montant_fcfa) . ' FCFA au titulaire.',
                default           => 'Ne remettez aucune somme.',
            },
            'motif'            => $r->motif_refus,
            'cagnotte'         => $cagnotte ? ['reference' => $cagnotte->reference, 'titre' => $cagnotte->titre] : null,
            // Le nom est ce que l'agent compare à la pièce d'identité ; il n'a
            // d'utilité que tant que le dossier attend son code.
            'titulaire'        => $enAttente && $cagnotte ? [
                'nom'            => $titulaire,
                'numero_masque'  => app(ReceiptService::class)->maskPhone((string) $cagnotte->numero_retrait),
            ] : null,
            'code_expire_at'   => $enAttente ? \Illuminate\Support\Carbon::parse($r->code_expire_at)->toIso8601String() : null,
            'essais_restants'  => $enAttente ? max(0, (int) config('retrait.code_tentatives') - (int) $r->code_tentatives) : null,
            'cree_at'          => \Illuminate\Support\Carbon::parse($r->created_at)->toIso8601String(),
            'termine_at'       => $r->termine_at ? \Illuminate\Support\Carbon::parse($r->termine_at)->toIso8601String() : null,
        ];
    }
}
