<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cagnottes (tontines + cotisations ouvertes) — côté gérant mobile.
 *
 * Règles métier appliquées (claude-link/context.md RÈGLE 4-bis) :
 *  - reference numérique 4-5 chiffres unique
 *  - numero_retrait_masque immutable après création
 *  - tontine : montant_par_cycle + periodicite + nombre_participants requis
 *  - cagnotte ouverte : montant_cible et date_fin optionnels
 *  - frais 2 % à la charge du cotisant (calculés à la cotisation, pas ici)
 *  - plafond 500 000 FCFA par transaction → nombre_splits dérivé du montant
 *    bénéficiaire pour le bookkeeping des envois
 */
class CagnottesController extends Controller
{
    /** Montant min indicatif cagnotte ouverte (FCFA). */
    private const MONTANT_MIN_DEFAULT = 100;
    /** Plafond par transaction Mobile Money (FCFA). */
    private const PLAFOND_PAR_ENVOI = 500_000;

    /**
     * GET /api/mobile/cagnottes
     * Query : ?statut=active|cloturee, ?type=...
     *
     * Liste les cagnottes dont l'utilisateur courant est gérant.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $q = TondoCagnotte::where('project_id', $user->project_id)
            ->where('user_id', $user->id)
            ->orderBy('date_creation', 'desc');

        if ($request->filled('statut')) {
            $q->where('statut', $request->string('statut'));
        }
        if ($request->filled('type')) {
            $q->where('type', $request->string('type'));
        }

        $rows = $q->limit(100)->get();

        return response()->json([
            'data' => $rows->map(fn ($c) => $this->serialize($c))->all(),
            'total' => $rows->count(),
        ]);
    }

    /**
     * POST /api/mobile/cagnottes
     *
     * Tontine périodique :
     *   { type: 'tontine_periodique', titre, numero_retrait,
     *     montant_par_cycle, periodicite, intervalle?, jour_semaine?, jour_mois?,
     *     nombre_participants }
     *
     * Cagnotte ouverte :
     *   { type: 'cagnotte_ouverte', titre, numero_retrait,
     *     montant_cible?, date_fin?, montant_min? }
     */
    public function store(Request $request): JsonResponse
    {
        $type = $request->input('type');
        if (! in_array($type, ['tontine_periodique', 'cagnotte_ouverte'], true)) {
            throw ValidationException::withMessages([
                'type' => 'Type invalide.',
            ]);
        }

        $base = $request->validate([
            'titre' => ['required', 'string', 'max:120'],
            'numero_retrait' => ['required', 'string', 'regex:/^\+?\d{8,15}$/'],
        ]);

        $cagnotte = new TondoCagnotte();
        $cagnotte->id = (string) Str::uuid();
        $cagnotte->project_id = $request->user()->project_id;
        $cagnotte->user_id = $request->user()->id;
        $cagnotte->titre = $base['titre'];
        $cagnotte->type = $type;
        $cagnotte->statut = 'active';
        $cagnotte->numero_retrait_masque = $this->maskPhone($base['numero_retrait']);

        if ($type === 'tontine_periodique') {
            $extra = $request->validate([
                'montant_par_cycle' => ['required', 'integer', 'min:100', 'max:5000000'],
                'periodicite' => ['required', Rule::in(['hebdomadaire', 'mensuelle'])],
                'intervalle' => ['nullable', 'integer', 'min:1', 'max:12'],
                'jour_semaine' => [
                    'nullable',
                    Rule::in(['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche']),
                ],
                'jour_mois' => ['nullable', 'integer', 'min:1', 'max:28'],
                'nombre_participants' => ['required', 'integer', 'min:2', 'max:200'],
            ]);

            $beneficiaire = $extra['montant_par_cycle'] * $extra['nombre_participants'];

            $cagnotte->montant_par_cycle = $extra['montant_par_cycle'];
            $cagnotte->periodicite = $extra['periodicite'];
            $cagnotte->intervalle = $extra['intervalle'] ?? 1;
            $cagnotte->jour_semaine = $extra['jour_semaine'] ?? null;
            $cagnotte->jour_mois = $extra['jour_mois'] ?? null;
            $cagnotte->nombre_participants = $extra['nombre_participants'];
            $cagnotte->nombre_envois = $extra['nombre_participants']; // 1 envoi par cycle
            $cagnotte->montant_beneficiaire = $beneficiaire;
            $cagnotte->nombre_splits = (int) ceil($beneficiaire / self::PLAFOND_PAR_ENVOI);
        } else {
            $extra = $request->validate([
                'montant_cible' => ['nullable', 'integer', 'min:1000'],
                'montant_min' => ['nullable', 'integer', 'min:100'],
                'date_fin' => ['nullable', 'date', 'after:today'],
            ]);

            $cagnotte->montant_cible = $extra['montant_cible'] ?? null;
            $cagnotte->date_fin = $extra['date_fin'] ?? null;
            $cagnotte->montant_beneficiaire = $extra['montant_cible'] ?? null;
            $cagnotte->nombre_participants = 0;
            $cagnotte->nombre_envois = 1;
            $cagnotte->nombre_splits = $extra['montant_cible']
                ? (int) ceil($extra['montant_cible'] / self::PLAFOND_PAR_ENVOI)
                : 1;
        }

        // Frais 2 % à la charge du cotisant, ajoutés sur le montant brut payé.
        if ($cagnotte->montant_beneficiaire) {
            $cagnotte->montant_avec_frais = (int) round($cagnotte->montant_beneficiaire * 1.02);
        }

        // Génère une référence 4-5 chiffres unique (retry si collision).
        $cagnotte->reference = $this->generateReference();

        $cagnotte->save();

        return response()->json([
            'cagnotte' => $this->serialize($cagnotte),
        ], 201);
    }

    /**
     * GET /api/mobile/cagnottes/{reference}
     *
     * Détail + participants + paiements (vue gérant). Renvoie 404 si
     * la cagnotte n'appartient pas à l'user (RLS applicatif).
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $user = $request->user();

        $cagnotte = TondoCagnotte::where('project_id', $user->project_id)
            ->where('reference', $reference)
            ->first();

        if (! $cagnotte) {
            return response()->json(['message' => 'Cagnotte introuvable.'], 404);
        }

        // Seul le gérant peut voir le détail (côté mobile). Participants
        // accèderont à un endpoint /public/cagnottes/by-ref plus tard.
        if ($cagnotte->user_id !== $user->id) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        $participants = DB::table('tondo_participants')
            ->where('cagnotte_id', $cagnotte->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'cagnotte' => $this->serialize($cagnotte),
            'participants' => $participants,
        ]);
    }

    /**
     * POST /api/mobile/cagnottes/{reference}/cloturer
     */
    public function cloturer(Request $request, string $reference): JsonResponse
    {
        $user = $request->user();

        $cagnotte = TondoCagnotte::where('project_id', $user->project_id)
            ->where('reference', $reference)
            ->where('user_id', $user->id)
            ->first();

        if (! $cagnotte) {
            return response()->json(['message' => 'Cagnotte introuvable.'], 404);
        }

        if ($cagnotte->statut === 'cloturee') {
            return response()->json(['message' => 'Déjà clôturée.'], 422);
        }

        $cagnotte->statut = 'cloturee';
        $cagnotte->save();

        return response()->json(['cagnotte' => $this->serialize($cagnotte)]);
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Génère une référence numérique 4 ou 5 chiffres unique (table-wide).
     * Démarre à 4 chiffres ; si collision après 5 tentatives, passe à 5.
     * Une fois plus de ~10k cagnottes, à étendre à 6 chiffres (à voir).
     */
    private function generateReference(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $ref = (string) random_int(1000, 9999);
            if (! TondoCagnotte::where('reference', $ref)->exists()) {
                return $ref;
            }
        }
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $ref = (string) random_int(10000, 99999);
            if (! TondoCagnotte::where('reference', $ref)->exists()) {
                return $ref;
            }
        }
        throw new \RuntimeException("Impossible de générer une référence unique après 15 essais.");
    }

    /**
     * Affichage masqué : "+241 77 ** ** 56" — on garde indicatif + 2
     * premiers et 2 derniers chiffres, le reste en *.
     */
    private function maskPhone(string $phone): string
    {
        $clean = preg_replace('/[^\d+]/', '', $phone);
        if (strlen($clean) < 6) {
            return $clean;
        }
        $prefix = substr($clean, 0, strlen($clean) - 6); // indicatif + opérateur
        $last2 = substr($clean, -2);

        return $prefix . str_repeat('*', strlen($clean) - strlen($prefix) - 2) . $last2;
    }

    private function serialize(TondoCagnotte $c): array
    {
        return [
            'id' => $c->id,
            'reference' => $c->reference,
            'titre' => $c->titre,
            'type' => $c->type,
            'statut' => $c->statut,
            'numero_retrait_masque' => $c->numero_retrait_masque,
            'montant_collecte' => (int) $c->montant_collecte,
            'montant_beneficiaire' => $c->montant_beneficiaire,
            'montant_avec_frais' => $c->montant_avec_frais,
            'montant_cible' => $c->montant_cible,
            'date_fin' => $c->date_fin?->toIso8601String(),
            'montant_par_cycle' => $c->montant_par_cycle,
            'periodicite' => $c->periodicite,
            'intervalle' => $c->intervalle,
            'jour_semaine' => $c->jour_semaine,
            'jour_mois' => $c->jour_mois,
            'nombre_participants' => $c->nombre_participants,
            'nombre_splits' => $c->nombre_splits,
            'nombre_envois' => $c->nombre_envois,
            'date_creation' => $c->date_creation?->toIso8601String(),
        ];
    }
}
