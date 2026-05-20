<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use App\Services\AirtelFeesCalculator;
use App\Services\TondoConfigService;
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
 *  - tontine : montant_par_cycle (= cash back par cycle) + periodicite + N participants
 *  - cagnotte ouverte : montant_cible (= cash back final) et date_fin optionnels
 *  - frais 2 % Paynala + frais Airtel retrait à la charge du cotisant (Modèle A)
 *  - plafond 500 000 FCFA par transaction → nombre_envois calculé par
 *    AirtelFeesCalculator (peut dépasser nombre_splits si régularisation)
 *
 * Modèle A (décidé 2026-05-12) : `montant_par_cycle` / `montant_cible` représente
 * le CASH NET livré au bénéficiaire. On calcule en remontant : envois Airtel
 * + commission Paynala = ce que paient effectivement les cotisants.
 */
class CagnottesController extends Controller
{
    /** Montant min indicatif cagnotte ouverte (FCFA). */
    private const MONTANT_MIN_DEFAULT = 100;

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

        $airtelConfig = app(TondoConfigService::class)->getOperatorConfig(
            $request->user()->project_id,
        );
        $calc         = new AirtelFeesCalculator($airtelConfig);
        $commission   = (float) $airtelConfig['commission_paynala'];

        if ($type === 'tontine_periodique') {
            $extra = $request->validate([
                // CASH BACK net livré au bénéficiaire à chaque cycle.
                // Plafond 2 500 000 = limite journalière émetteur Airtel.
                'montant_par_cycle' => ['required', 'integer', 'min:100', 'max:2500000'],
                'periodicite' => ['required', Rule::in(['hebdomadaire', 'mensuelle'])],
                'intervalle' => ['nullable', 'integer', 'min:1', 'max:12'],
                'jour_semaine' => [
                    'nullable',
                    Rule::in(['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche']),
                ],
                'jour_mois' => ['nullable', 'integer', 'min:1', 'max:28'],
                'nombre_participants' => ['required', 'integer', 'min:2', 'max:200'],
            ]);

            $cashBack = $extra['montant_par_cycle'];
            $plan = $calc->plan($cashBack);
            $this->appliquerPlan($cagnotte, $plan, $cashBack, $commission);

            $cagnotte->montant_par_cycle = $cashBack;
            $cagnotte->periodicite = $extra['periodicite'];
            $cagnotte->intervalle = $extra['intervalle'] ?? 1;
            $cagnotte->jour_semaine = $extra['jour_semaine'] ?? null;
            $cagnotte->jour_mois = $extra['jour_mois'] ?? null;
            $cagnotte->nombre_participants = $extra['nombre_participants'];
        } else {
            $extra = $request->validate([
                'montant_cible' => ['nullable', 'integer', 'min:100', 'max:2500000'],
                'montant_min' => ['nullable', 'integer', 'min:100'],
                'date_fin' => ['nullable', 'date', 'after:today'],
            ]);

            $cagnotte->montant_cible = $extra['montant_cible'] ?? null;
            $cagnotte->date_fin = $extra['date_fin'] ?? null;
            $cagnotte->nombre_participants = 0;

            if (! empty($extra['montant_cible'])) {
                $plan = $calc->plan($extra['montant_cible']);
                $this->appliquerPlan($cagnotte, $plan, $extra['montant_cible'], $commission);
            } else {
                // Cagnotte sans cible : pas de plan d'envoi pré-calculé,
                // on recalculera à la clôture sur le montant_collecte effectif.
                $cagnotte->montant_beneficiaire = null;
                $cagnotte->montant_avec_frais = null;
                $cagnotte->total_a_envoyer = null;
                $cagnotte->nombre_envois = null;
                $cagnotte->nombre_splits = null;
            }
        }

        // Génère une référence 4-5 chiffres unique (retry si collision).
        $cagnotte->reference = $this->generateReference();
        $cagnotte->date_creation = now();

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
     * DELETE /api/mobile/cagnottes/{reference}
     *
     * Supprime une cagnotte uniquement si aucun versement n'a encore été
     * enregistré (montant_collecte = 0). Règle anti-fraude : impossible de
     * supprimer une cagnotte qui a reçu de l'argent.
     */
    public function destroy(Request $request, string $reference): JsonResponse
    {
        $user = $request->user();

        $cagnotte = TondoCagnotte::where('project_id', $user->project_id)
            ->where('reference', $reference)
            ->where('user_id', $user->id)
            ->first();

        if (! $cagnotte) {
            return response()->json(['message' => 'Cagnotte introuvable.'], 404);
        }

        if ((int) $cagnotte->montant_collecte > 0) {
            return response()->json([
                'message' => 'Impossible de supprimer une cagnotte ayant reçu des versements.',
            ], 422);
        }

        $cagnotte->delete();

        return response()->json(['message' => 'Cagnotte supprimée.'], 200);
    }

    /**
     * POST /api/mobile/cagnottes/{reference}/participants
     *
     * Ajoute un participant à une cagnotte. Seul le gérant peut appeler cet endpoint.
     *
     * Corps : { numero, nom?, prenom? }
     *   - Si le numéro est trouvé dans tondo_users, nom/prénom sont récupérés automatiquement.
     *   - Sinon, nom et prénom sont obligatoires dans le corps.
     *
     * Retourne 201 + { participant } en cas de succès.
     * Retourne 422 si numéro déjà présent ou si nom/prénom manquants.
     */
    public function storeParticipant(Request $request, string $reference): JsonResponse
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
            return response()->json(['message' => 'Impossible de modifier une cagnotte clôturée.'], 422);
        }

        $data = $request->validate([
            'numero' => ['required', 'string', 'regex:/^\+?\d{8,15}$/'],
            'nom'    => ['nullable', 'string', 'max:60'],
            'prenom' => ['nullable', 'string', 'max:60'],
        ]);

        $numero      = preg_replace('/[\s\-]/', '', $data['numero']);
        $numeroMasque = $this->maskPhone($numero);

        // Doublon : même numéro masqué dans la même cagnotte
        $dejaPrecent = DB::table('tondo_participants')
            ->where('cagnotte_id', $cagnotte->id)
            ->where('numero_masque', $numeroMasque)
            ->exists();

        if ($dejaPrecent) {
            return response()->json(['message' => 'Ce participant est déjà dans la cagnotte.'], 422);
        }

        // Recherche du compte Tondo si le numéro est enregistré
        $utilisateur = \App\Models\TondoUser::where('project_id', $user->project_id)
            ->where('numero', $numero)
            ->first();

        // Si user_id déjà participant (numéro différent mais même compte)
        if ($utilisateur) {
            $dejaParUserId = DB::table('tondo_participants')
                ->where('cagnotte_id', $cagnotte->id)
                ->where('user_id', $utilisateur->id)
                ->exists();
            if ($dejaParUserId) {
                return response()->json(['message' => 'Cet utilisateur est déjà dans la cagnotte.'], 422);
            }
        }

        $nom    = $utilisateur?->nom    ?? $data['nom']    ?? null;
        $prenom = $utilisateur?->prenom ?? $data['prenom'] ?? null;

        if (! $nom || ! $prenom) {
            return response()->json([
                'message' => 'Numéro non enregistré — nom et prénom requis.',
                'errors'  => [
                    'nom'    => ['Le nom est requis pour ce numéro.'],
                    'prenom' => ['Le prénom est requis pour ce numéro.'],
                ],
            ], 422);
        }

        $participantId = (string) \Illuminate\Support\Str::uuid();
        DB::table('tondo_participants')->insert([
            'id'          => $participantId,
            'project_id'  => $user->project_id,
            'cagnotte_id' => $cagnotte->id,
            'user_id'     => $utilisateur?->id,
            'nom'         => $nom,
            'prenom'      => $prenom,
            'numero_masque' => $numeroMasque,
            'statut_paiement' => 'en_attente',
            'montant_paye' => 0,
            'created_at'  => now(),
        ]);

        // Mise à jour du compteur
        $cagnotte->nombre_participants = DB::table('tondo_participants')
            ->where('cagnotte_id', $cagnotte->id)
            ->count();
        $cagnotte->save();

        return response()->json([
            'participant' => [
                'id'              => $participantId,
                'nom'             => $nom,
                'prenom'          => $prenom,
                'numero_masque'   => $numeroMasque,
                'statut_paiement' => 'en_attente',
                'montant_paye'    => 0,
                'dans_systeme'    => $utilisateur !== null,
            ],
        ], 201);
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
     * Applique le plan de décaissement Airtel + commission Paynala sur la
     * cagnotte. Modèle A : tous les frais (Airtel retrait + Paynala 2 %)
     * sont absorbés par les cotisants ; le bénéficiaire reçoit exactement
     * `$cashBack` FCFA en main.
     *
     *   montant_beneficiaire = cash net livré au bénéficiaire
     *   total_a_envoyer      = cash + frais Airtel (= ce qui débite le wallet émetteur)
     *   montant_avec_frais   = total_a_envoyer + 2 % Paynala (= total payé par les cotisants)
     */
    private function appliquerPlan(TondoCagnotte $cagnotte, array $plan, int $cashBack, float $commission): void
    {
        $totalAEnvoyer = $plan['total_a_envoyer'];
        $montantAvecFrais = (int) ceil($totalAEnvoyer * (1 + $commission));

        $cagnotte->montant_beneficiaire = $cashBack;
        $cagnotte->total_a_envoyer = $totalAEnvoyer;
        $cagnotte->montant_avec_frais = $montantAvecFrais;
        $cagnotte->nombre_splits = $plan['nombre_splits'];
        $cagnotte->nombre_envois = $plan['nombre_envois'];
    }

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
            'total_a_envoyer' => $c->total_a_envoyer,
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
