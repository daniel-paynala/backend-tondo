<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\GereRetraitAgents;
use App\Http\Controllers\Controller;
use App\Services\PaynalaPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Répartition des frais : les comptes qui reçoivent une part, et combien.
 *
 * Réservé au **super admin** et journalisé : décider qui prélève sur l'argent
 * des utilisateurs est une décision lourde, il faut pouvoir dire plus tard qui
 * l'a prise.
 *
 * Le taux d'un service EST la somme de ses lignes actives. Il n'y a pas de
 * « taux du service » à maintenir à côté — ce serait deux sources pour une même
 * information, et elles finiraient par se contredire.
 */
class FraisComptesController extends Controller
{
    // Le trait porte déjà `exigerSuperAdmin()` et l'écriture au journal
    // d'audit, avec la même forme que l'habilitation des agents de retrait —
    // l'autre endroit où l'on autorise un tiers à toucher de l'argent.
    use GereRetraitAgents;

    private const SERVICES = ['transfert', 'marchand'];

    /**
     * GET /api/admin/frais-comptes
     *
     * Renvoie les comptes groupés par service, avec le total de chaque service.
     */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;

        $comptes = DB::table(project_table('frais_comptes'))
            ->where('project_id', $projectId)
            ->orderBy('service')
            ->orderBy('created_at')
            ->get(['id', 'service', 'libelle', 'numero_tel', 'type_paynala', 'taux', 'actif']);

        $parService = [];
        foreach (self::SERVICES as $service) {
            $lignes = $comptes->where('service', $service)->values();

            $parService[$service] = [
                'comptes' => $lignes->map(fn ($c) => [
                    'id'           => $c->id,
                    'libelle'      => $c->libelle,
                    'numero_tel'   => $c->numero_tel,
                    'type_paynala' => $c->type_paynala,
                    'taux'         => (float) $c->taux,
                    'actif'        => (bool) $c->actif,
                ])->all(),
                // Total des lignes ACTIVES seulement : une ligne désactivée ne
                // prélève rien, elle ne doit pas gonfler le taux annoncé.
                'taux_total' => (float) $lignes->where('actif', true)->sum('taux'),
            ];
        }

        return response()->json([
            'services' => $parService,
            // Ce que l'opérateur impose comme minimum. Le dashboard l'affiche
            // pour expliquer pourquoi une petite part n'est pas partie tout de
            // suite : elle s'accumule.
            'plancher_decaissement' => (int) config('services.paynala.montant_minimum_disburse', 100),
        ]);
    }

    /**
     * POST /api/admin/frais-comptes — super_admin.
     */
    public function store(Request $request): JsonResponse
    {
        $this->exigerSuperAdmin($request);

        $data = $this->valider($request);
        $projectId = $request->user()->project_id;

        // Un même numéro ne peut pas porter deux parts du même service : on ne
        // saurait plus laquelle appliquer, et le cumul serait silencieux.
        $existe = DB::table(project_table('frais_comptes'))
            ->where('project_id', $projectId)
            ->where('service', $data['service'])
            ->where('numero_tel', $data['numero_tel'])
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'numero_tel' => 'Ce numéro porte déjà une part sur ce service.',
            ]);
        }

        $id = (string) Str::uuid();

        DB::table(project_table('frais_comptes'))->insert([
            'id'           => $id,
            'project_id'   => $projectId,
            'service'      => $data['service'],
            'libelle'      => $data['libelle'],
            'numero_tel'   => $data['numero_tel'],
            'type_paynala' => $data['type_paynala'],
            'taux'         => $data['taux'],
            'actif'        => $data['actif'] ?? true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // `warning` et non `info` : décider qui prélève sur l'argent des
        // utilisateurs doit ressortir d'une relecture du journal.
        $this->journaliser($request, 'frais_compte_cree', $data['libelle'], 'warning', $data);

        return response()->json(['id' => $id], 201);
    }

    /**
     * PATCH /api/admin/frais-comptes/{id} — super_admin.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);

        $data = $this->valider($request, partiel: true);

        $modifie = DB::table(project_table('frais_comptes'))
            ->where('project_id', $request->user()->project_id)
            ->where('id', $id)
            ->update(array_merge($data, ['updated_at' => now()]));

        if ($modifie === 0) {
            return response()->json(['message' => 'Compte introuvable.'], 404);
        }

        $this->journaliser($request, 'frais_compte_modifie', $id, 'warning', $data);

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /api/admin/frais-comptes/{id} — super_admin.
     *
     * Refusé si le compte doit encore de l'argent : le journal des parts
     * pointe vers lui, et un historique doit rester lisible. On désactive à la
     * place, ce que la contrainte `ON DELETE RESTRICT` impose de toute façon.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->exigerSuperAdmin($request);

        $dues = DB::table(project_table('frais_dus'))
            ->where('compte_id', $id)
            ->whereIn('statut', ['du', 'incertain'])
            ->count();

        if ($dues > 0) {
            return response()->json([
                'message' => "Ce compte a {$dues} part(s) non réglée(s). Désactivez-le plutôt que de le supprimer.",
            ], 409);
        }

        $supprime = DB::table(project_table('frais_comptes'))
            ->where('project_id', $request->user()->project_id)
            ->where('id', $id)
            ->delete();

        if ($supprime === 0) {
            return response()->json(['message' => 'Compte introuvable.'], 404);
        }

        $this->journaliser($request, 'frais_compte_supprime', $id, 'warning', ['id' => $id]);

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/admin/frais-dus
     *
     * Ce que chaque compte a reçu, et ce qu'il attend encore. Les parts
     * `incertain` sont montrées en premier : ce sont les seules qui demandent
     * une décision humaine.
     */
    public function dus(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;
        $dus       = project_table('frais_dus');
        $comptes   = project_table('frais_comptes');

        $lignes = DB::table($dus)
            ->join($comptes, "{$comptes}.id", '=', "{$dus}.compte_id")
            ->where("{$dus}.project_id", $projectId)
            ->orderByRaw("CASE {$dus}.statut WHEN 'incertain' THEN 0 WHEN 'echec' THEN 1 WHEN 'du' THEN 2 ELSE 3 END")
            ->orderByDesc("{$dus}.created_at")
            ->limit(500)
            ->get([
                "{$dus}.id", "{$dus}.montant", "{$dus}.statut", "{$dus}.trans_id",
                "{$dus}.created_at", "{$dus}.regle_at",
                "{$comptes}.libelle", "{$comptes}.service",
            ]);

        // Cumuls par statut : « combien attend-on de régler ? » est la question
        // qu'on se pose en arrivant sur l'écran.
        $totaux = DB::table($dus)
            ->where('project_id', $projectId)
            ->groupBy('statut')
            ->get([DB::raw('statut'), DB::raw('SUM(montant) AS total'), DB::raw('COUNT(*) AS nb')]);

        return response()->json([
            'lignes' => $lignes,
            'totaux' => $totaux->mapWithKeys(fn ($t) => [
                $t->statut => ['total' => (int) $t->total, 'nb' => (int) $t->nb],
            ]),
        ]);
    }

    /**
     * Valide la saisie d'un compte de frais.
     *
     * @return array<string, mixed>
     */
    private function valider(Request $request, bool $partiel = false): array
    {
        $requis = $partiel ? 'sometimes' : 'required';

        $data = $request->validate([
            'service'      => [$requis, 'string', 'in:transfert,marchand'],
            'libelle'      => [$requis, 'string', 'max:80'],
            // E.164 comme partout ailleurs dans le produit.
            'numero_tel'   => [$requis, 'string', 'regex:/^\+241\d{8,9}$/'],
            'type_paynala' => [$requis, 'string', 'in:particulier,entreprise'],
            // Borne haute par ligne : elle écarte la faute de frappe « 1 » pour
            // 1 %, qui prélèverait la totalité du transfert.
            'taux'         => [$requis, 'numeric', 'min:0', 'max:0.25'],
            'actif'        => ['sometimes', 'boolean'],
        ]);

        // Le grade du compte receveur doit correspondre au routage de l'API :
        // B2C sur un compte pro — ou l'inverse — fait répondre « Transaction
        // Ambiguous » et débite pour rien.
        if (isset($data['type_paynala'])) {
            PaynalaPaymentService::modeDisburse($data['type_paynala']);
        }

        return $data;
    }
}
