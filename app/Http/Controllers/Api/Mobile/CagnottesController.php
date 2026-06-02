<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use App\Services\AirtelFeesCalculator;
use App\Services\OneSignalService;
use App\Services\TondoConfigService;
use App\Services\TontineService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
     * Retourne :
     *   - les cagnottes dont l'user est gérant (role_utilisateur = 'gerant')
     *   - les cagnottes dont l'user est participant cotiseur (role_utilisateur = 'cotiseur'),
     *     c.-à-d. celles où il apparaît dans tondo_participants avec son user_id.
     *     Pour les cagnottes ouvertes, l'entrée participant est créée automatiquement
     *     au premier paiement (CotisationsController::store).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $filtreStatut = $request->filled('statut') ? $request->string('statut')->toString() : null;
        $filtreType   = $request->filled('type')   ? $request->string('type')->toString()   : null;

        // ── Cagnottes gérant ─────────────────────────────────────────────
        $qGerant = TondoCagnotte::where('project_id', $user->project_id)
            ->where('user_id', $user->id)
            ->orderBy('date_creation', 'desc');
        if ($filtreStatut) $qGerant->where('statut', $filtreStatut);
        if ($filtreType)   $qGerant->where('type',   $filtreType);

        $gerant = $qGerant->limit(100)->get()
            ->map(fn ($c) => array_merge($this->serialize($c), ['role_utilisateur' => 'gerant']));

        // ── Cagnottes cotiseur ────────────────────────────────────────────
        // Récupère les IDs des cagnottes où l'user est participant (user_id connu).
        $cagnotteIdsCotiseur = DB::table('tondo_participants')
            ->where('user_id', $user->id)
            ->pluck('cagnotte_id');

        $qCotiseur = TondoCagnotte::where('project_id', $user->project_id)
            ->where('user_id', '!=', $user->id)   // exclure celles où il est gérant
            ->whereIn('id', $cagnotteIdsCotiseur)
            ->orderBy('date_creation', 'desc');
        if ($filtreStatut) $qCotiseur->where('statut', $filtreStatut);
        if ($filtreType)   $qCotiseur->where('type',   $filtreType);

        $cotiseur = $qCotiseur->limit(100)->get()
            ->map(fn ($c) => array_merge($this->serialize($c), ['role_utilisateur' => 'cotiseur']));

        $all = $gerant->concat($cotiseur)
            ->sortByDesc('date_creation')
            ->values();

        return response()->json([
            'data'  => $all->all(),
            'total' => $all->count(),
        ]);
    }

    /**
     * GET /api/mobile/cagnottes/generate-reference
     *
     * Génère et retourne une référence unique à 6 chiffres à afficher
     * dans le formulaire de création. Le client la renvoie ensuite dans
     * le POST /cagnottes pour garantir que ce que l'utilisateur a vu
     * correspond exactement à ce qui est enregistré en base.
     */
    public function generateReference(Request $request): JsonResponse
    {
        return response()->json([
            'reference' => $this->doGenerateReference(),
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
            'titre'         => ['required', 'string', 'max:120'],
            'numero_retrait' => ['required', 'string', 'regex:/^\+?\d{8,15}$/'],
            'reference'     => ['nullable', 'string', 'regex:/^\d{6}$/'],
        ]);

        $user = $request->user();

        $cagnotte = new TondoCagnotte();
        $cagnotte->id = (string) Str::uuid();
        $cagnotte->project_id = $user->project_id;
        $cagnotte->user_id = $user->id;
        $cagnotte->titre = $base['titre'];
        $cagnotte->type = $type;
        $cagnotte->statut = 'active';
        $cagnotte->numero_retrait_masque = $this->maskPhone($base['numero_retrait']);

        $airtelConfig = app(TondoConfigService::class)->getOperatorConfig(
            $user->project_id,
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

        // Référence : utilise celle pré-générée par le client (ce que l'utilisateur
        // a vu) si elle est unique, sinon en génère une nouvelle (fallback).
        $refDemandee = $base['reference'] ?? null;
        if ($refDemandee && ! TondoCagnotte::where('reference', $refDemandee)->exists()) {
            $cagnotte->reference = $refDemandee;
        } else {
            $cagnotte->reference = $this->doGenerateReference();
        }
        $cagnotte->date_creation = now();

        $cagnotte->save();

        // Le créateur est automatiquement inscrit comme premier participant.
        if ($type === 'tontine_periodique') {
            DB::table('tondo_participants')->insert([
                'id'              => (string) Str::uuid(),
                'project_id'      => $user->project_id,
                'cagnotte_id'     => $cagnotte->id,
                'user_id'         => $user->id,
                'nom'             => $user->nom,
                'prenom'          => $user->prenom,
                'numero_masque'   => $this->maskPhone($user->numero),
                'statut_paiement' => 'en_attente',
                'montant_paye'    => 0,
                'created_at'      => now(),
            ]);
            // nombre_inscrits ne compte que les participants AJOUTÉS après création.
            // Le créateur est toujours +1 implicite — l'UI ajoute ce +1 à l'affichage.
        }

        return response()->json([
            'cagnotte' => $this->serialize($cagnotte),
        ], 201);
    }

    /**
     * GET /api/mobile/cagnottes/{reference}
     *
     * Détail + participants. Accessible à tout utilisateur authentifié
     * qui connaît la référence (flux "Rejoindre"). Le rôle est renvoyé
     * dans la réponse pour que le client adapte son UI.
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

        $estGerant = $cagnotte->user_id === $user->id;

        $configSvc    = app(TondoConfigService::class);
        $userMasque   = $this->maskPhone($user->numero);
        // Montants déjà reçus par participant via payout (pour transparence reversements).
        $montantsRecusParParticipant = DB::table('tondo_payout')
            ->where('cagnotte_id', $cagnotte->id)
            ->where('statut', 'succes')
            ->whereNotNull('user_id')
            ->select('user_id', DB::raw('SUM(montant) as total_recu'))
            ->groupBy('user_id')
            ->pluck('total_recu', 'user_id');

        $participants = DB::table('tondo_participants')
            ->where('cagnotte_id', $cagnotte->id)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($p) use ($user, $userMasque, $configSvc, $montantsRecusParParticipant) {
                $opInfo = $configSvc->detectOperateur($p->numero_masque, $user->project_id);
                $isMe = ($p->user_id !== null && $p->user_id === $user->id)
                    || $p->numero_masque === $userMasque;
                return [
                    'id'                        => $p->id,
                    'nom'                       => $p->nom,
                    'prenom'                    => $p->prenom,
                    'numero_masque'             => $p->numero_masque,
                    'numero_retrait_masque'     => $p->numero_retrait_masque ?? null,
                    'est_compte_light'          => (bool) ($p->est_compte_light ?? false),
                    'statut_paiement'           => $p->statut_paiement,
                    'montant_paye'              => $p->montant_paye,
                    'date_dernier_paiement'     => $p->date_dernier_paiement,
                    'is_me'                     => $isMe,
                    'operateur'                 => $opInfo['operateur'],
                    'operateur_logo'            => $opInfo['operateur_logo'],
                    'ordre_passage'             => (int) ($p->ordre_passage ?? 0),
                    'montant_recu_reversement'  => (int) ($p->user_id
                        ? ($montantsRecusParParticipant[$p->user_id] ?? 0)
                        : 0),
                ];
            });

        // Cycles complétés = payouts confirmés sur cette cagnotte.
        $cyclesCompletes = (int) DB::table('tondo_payout')
            ->where('cagnotte_id', $cagnotte->id)
            ->where('statut', 'succes')
            ->count();

        $participantsArray = $participants->values()->all();
        $nbInscrits = (int) $cagnotte->nombre_inscrits;

        // Historique des cotisations reçues (entrées).
        $historiqueQuery = DB::table('tondo_paiements')
            ->join('tondo_participants', 'tondo_paiements.participant_id', '=', 'tondo_participants.id')
            ->where('tondo_paiements.cagnotte_id', $cagnotte->id)
            ->orderBy('tondo_paiements.date', 'desc')
            ->limit(50)
            ->select(
                'tondo_paiements.id',
                'tondo_paiements.participant_id',
                'tondo_paiements.montant',
                'tondo_paiements.date',
                DB::raw("CONCAT(tondo_participants.prenom, ' ', tondo_participants.nom) as participant_nom")
            );

        // Cotiseur : filtre sur ses propres paiements uniquement (privacy).
        if (! $estGerant) {
            $historiqueQuery->where('tondo_paiements.user_id', $user->id);
        }

        $historique = $historiqueQuery->get()->map(fn ($h) => [
            'id'             => $h->id,
            'participant_id' => $h->participant_id,
            'participant_nom'=> $h->participant_nom,
            'montant'        => $h->montant,
            'date'           => $h->date,
        ]);

        // Sorties (reversements effectués) — visibles par tous (transparence).
        $sorties = DB::table('tondo_payout')
            ->where('cagnotte_id', $cagnotte->id)
            ->where('statut', 'succes')
            ->orderBy('date_creation', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($r) {
                // Résout le nom du bénéficiaire si user_id connu.
                $nomBenef = 'Bénéficiaire';
                if ($r->user_id) {
                    $u = DB::table('users')->where('id', $r->user_id)
                        ->select('nom', 'prenom')->first();
                    if ($u) $nomBenef = trim("{$u->prenom} {$u->nom}");
                }
                return [
                    'id'                  => $r->id,
                    'beneficiaire_nom'    => $nomBenef,
                    'beneficiaire_numero' => $r->numero_tel ?? '',
                    'montant'             => $r->montant,
                    'date'                => $r->date_creation,
                ];
            });

        return response()->json([
            'cagnotte'     => array_merge(
                $this->serialize($cagnotte),
                [
                    'role_utilisateur'      => $estGerant ? 'gerant' : 'cotiseur',
                    'prochain_retrait'      => app(TontineService::class)->prochaineDate($cagnotte, $cyclesCompletes),
                    'prochain_beneficiaire' => $this->calculerProchainBeneficiaire($participantsArray, $cyclesCompletes),
                    'rotation_terminee'     => $cagnotte->statut === 'en_cours'
                        && $cagnotte->type === 'tontine_periodique'
                        && $nbInscrits > 0
                        && $cyclesCompletes >= $nbInscrits,
                ]
            ),
            'participants' => $participants,
            'historique'   => $historique,
            'sorties'      => $sorties,
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

        // Contrôle capacité : pour une tontine périodique, le créateur occupe
        // une place (non comptée dans nombre_inscrits). Tontine pleine si
        // nombre_inscrits + 1 (créateur) >= nombre_participants.
        if ($cagnotte->type === 'tontine_periodique' && (int) $cagnotte->nombre_participants > 0) {
            if ((int) $cagnotte->nombre_inscrits + 1 >= (int) $cagnotte->nombre_participants) {
                return response()->json([
                    'message' => 'Tontine complète — le nombre maximum de participants est atteint.',
                ], 422);
            }
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

        $opInfo = app(TondoConfigService::class)->detectOperateur($numero, $user->project_id);

        // Crée un compte light si le participant n'a pas encore de compte Tondo.
        // Les comptes light ont accès au paiement via web/WhatsApp uniquement.
        // Ils peuvent s'inscrire normalement ensuite — leur compte sera upgradé.
        $wasExisting = $utilisateur !== null;
        if (! $utilisateur) {
            $utilisateur = new \App\Models\TondoUser();
            $utilisateur->id          = (string) Str::uuid();
            $utilisateur->project_id  = $user->project_id;
            $utilisateur->nom         = $nom;
            $utilisateur->prenom      = $prenom;
            $utilisateur->numero      = $numero;
            $utilisateur->compte_type = 'light';
            $utilisateur->type_client = 'particulier';
            $utilisateur->kyc_valide  = false;
            if ($opInfo['operateur']) {
                $utilisateur->operateur = $opInfo['operateur'];
            }
            $utilisateur->save();
        }

        $participantId = (string) \Illuminate\Support\Str::uuid();
        DB::table('tondo_participants')->insert([
            'id'              => $participantId,
            'project_id'      => $user->project_id,
            'cagnotte_id'     => $cagnotte->id,
            'user_id'         => $utilisateur->id,
            'nom'             => $nom,
            'prenom'          => $prenom,
            'numero_masque'   => $numeroMasque,
            'statut_paiement' => 'en_attente',
            'montant_paye'    => 0,
            'created_at'      => now(),
        ]);

        // Incrémente le compteur d'inscrits (nombre_participants reste la cible déclarée).
        $cagnotte->increment('nombre_inscrits');

        // Notifie le participant ajouté (uniquement les comptes full avec device enregistré).
        if ($utilisateur && ($utilisateur->compte_type ?? 'full') === 'full') {
            app(OneSignalService::class)->notifyOne(
                userId:  $utilisateur->id,
                titleFr: 'Vous avez été ajouté à une tontine',
                bodyFr:  "Vous participez maintenant à « {$cagnotte->titre} ».",
                data:    ['type' => 'ajout_tontine', 'cagnotte_id' => $cagnotte->id],
            );
        }

        return response()->json([
            'participant' => [
                'id'              => $participantId,
                'nom'             => $nom,
                'prenom'          => $prenom,
                'numero_masque'   => $numeroMasque,
                'statut_paiement' => 'en_attente',
                'montant_paye'    => 0,
                'dans_systeme'    => $wasExisting,
                'operateur'       => $opInfo['operateur'],
                'operateur_logo'  => $opInfo['operateur_logo'],
            ],
        ], 201);
    }

    /**
     * POST /api/mobile/cagnottes/{reference}/demarrer
     *
     * Passe le statut de la tontine de `active` à `en_cours`.
     * Une fois démarrée, l'ordre de passage est verrouillé.
     */
    public function demarrer(Request $request, string $reference): JsonResponse
    {
        $user = $request->user();

        $cagnotte = TondoCagnotte::where('project_id', $user->project_id)
            ->where('reference', $reference)
            ->where('user_id', $user->id)
            ->first();

        if (! $cagnotte) {
            return response()->json(['message' => 'Cagnotte introuvable.'], 404);
        }
        if ($cagnotte->type !== 'tontine_periodique') {
            return response()->json(['message' => 'Seules les tontines peuvent être démarrées.'], 422);
        }
        if ($cagnotte->statut !== 'active') {
            return response()->json(['message' => 'La tontine doit être active pour être démarrée.'], 422);
        }
        if ((int) $cagnotte->nombre_inscrits < 1) {
            return response()->json(['message' => 'Ajoutez au moins un participant avant de démarrer.'], 422);
        }

        $cagnotte->statut        = 'en_cours';
        $cagnotte->date_demarrage = now();
        $cagnotte->save();

        // Récupère tous les participants ayant un compte full pour les notifier.
        $participants = DB::table('tondo_participants')
            ->join('users', 'users.id', '=', 'tondo_participants.user_id')
            ->where('tondo_participants.cagnotte_id', $cagnotte->id)
            ->where('users.compte_type', 'full')
            ->select('users.id as user_id', 'tondo_participants.ordre_passage')
            ->get();

        $notifSvc = app(OneSignalService::class);

        // Notifie tous les participants du démarrage.
        $tousLesIds = $participants->pluck('user_id')->filter()->values()->all();
        if (! empty($tousLesIds)) {
            $notifSvc->notify(
                userIds: $tousLesIds,
                titleFr: 'La tontine a démarré !',
                bodyFr:  "« {$cagnotte->titre} » est maintenant en cours.",
                data:    ['type' => 'tontine_demarree', 'cagnotte_id' => $cagnotte->id],
            );
        }

        // Notifie spécifiquement le premier bénéficiaire (ordre_passage = 1).
        $premier = $participants->firstWhere('ordre_passage', 1);
        if ($premier) {
            $notifSvc->notifyOne(
                userId:  $premier->user_id,
                titleFr: 'Vous êtes le premier bénéficiaire !',
                bodyFr:  "C'est vous qui recevrez la première mise de « {$cagnotte->titre} ».",
                data:    ['type' => 'premier_beneficiaire', 'cagnotte_id' => $cagnotte->id],
            );
        }

        return response()->json(['cagnotte' => $this->serialize($cagnotte)]);
    }

    /**
     * POST /api/mobile/cagnottes/{reference}/participants/ordre
     *
     * Enregistre l'ordre de passage des participants.
     * Corps : { "ordre": ["uuid1", "uuid2", ...] } — liste ordonnée d'IDs.
     * Interdit si la tontine est déjà en_cours.
     */
    public function ordonnerParticipants(Request $request, string $reference): JsonResponse
    {
        $user = $request->user();

        $cagnotte = TondoCagnotte::where('project_id', $user->project_id)
            ->where('reference', $reference)
            ->where('user_id', $user->id)
            ->first();

        if (! $cagnotte) {
            return response()->json(['message' => 'Cagnotte introuvable.'], 404);
        }
        if ($cagnotte->statut === 'en_cours') {
            return response()->json(['message' => 'L\'ordre ne peut plus être modifié après démarrage.'], 422);
        }

        $data = $request->validate([
            'ordre'   => ['required', 'array'],
            'ordre.*' => ['required', 'string'],
        ]);

        // Auto-heal : participants ajoutés avant la fonctionnalité "comptes light"
        // n'ont pas de user_id. On leur crée un compte light minimal avec un numéro
        // synthétique (non utilisable pour SMS/paiement) avant d'enregistrer l'ordre.
        $this->healParticipantsSansCompte($data['ordre'], $cagnotte->id, $user->project_id);

        foreach ($data['ordre'] as $position => $participantId) {
            DB::table('tondo_participants')
                ->where('id', $participantId)
                ->where('cagnotte_id', $cagnotte->id)
                ->update(['ordre_passage' => $position + 1]);
        }

        return response()->json(['message' => 'Ordre enregistré.']);
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
     * Crée des comptes light pour les participants qui n'en ont pas encore
     * (ajoutés avant la fonctionnalité comptes light, donc user_id = null).
     *
     * Le numéro synthétique `+00000XXXXXXXXXX` est déterministe (hash du
     * participant ID) et clairement fictif (indicatif +00000 inexistant).
     * Il ne servira jamais à un SMS ou un paiement — rôle : ancrer l'identité
     * du participant pour les futures fonctionnalités nécessitant user_id.
     */
    private function healParticipantsSansCompte(array $participantIds, string $cagnotteId, string $projectId): void
    {
        $orphelins = DB::table('tondo_participants')
            ->whereIn('id', $participantIds)
            ->where('cagnotte_id', $cagnotteId)
            ->whereNull('user_id')
            ->get();

        foreach ($orphelins as $p) {
            // Numéro synthétique unique et reproductible : +00000 + 10 chiffres
            $syntheticNumero = '+00000' . str_pad((string) abs(crc32($p->id)), 10, '0', STR_PAD_LEFT);

            // Si un compte light a déjà été créé pour ce même numéro (idempotence)
            $existant = \App\Models\TondoUser::where('project_id', $projectId)
                ->where('numero', $syntheticNumero)
                ->first();

            if (! $existant) {
                $newUser = new \App\Models\TondoUser();
                $newUser->id          = (string) \Illuminate\Support\Str::uuid();
                $newUser->project_id  = $projectId;
                $newUser->nom         = $p->nom;
                $newUser->prenom      = $p->prenom;
                $newUser->numero      = $syntheticNumero;
                $newUser->compte_type = 'light';
                $newUser->type_client = 'particulier';
                $newUser->kyc_valide  = false;
                $newUser->save();
                $existant = $newUser;
            }

            DB::table('tondo_participants')
                ->where('id', $p->id)
                ->update(['user_id' => $existant->id]);
        }
    }

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
    private function doGenerateReference(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $ref = (string) random_int(100000, 999999);
            if (! TondoCagnotte::where('reference', $ref)->exists()) {
                return $ref;
            }
        }
        throw new \RuntimeException("Impossible de générer une référence 6 chiffres unique après 20 essais.");
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

    /**
     * POST /api/mobile/cagnottes/{reference}/rappel
     *
     * Le gérant envoie un rappel de paiement à tous les participants
     * dont le statut est encore `en_attente`.
     * Réservé au créateur de la cagnotte.
     */
    public function rappel(Request $request, string $reference): JsonResponse
    {
        $user = $request->user();

        $cagnotte = TondoCagnotte::where('project_id', $user->project_id)
            ->where('reference', $reference)
            ->where('user_id', $user->id)
            ->first();

        if (! $cagnotte) {
            return response()->json(['message' => 'Cagnotte introuvable ou accès refusé.'], 404);
        }

        // Participants en retard ayant un compte full.
        $participantsEnAttente = DB::table('tondo_participants')
            ->join('users', 'users.id', '=', 'tondo_participants.user_id')
            ->where('tondo_participants.cagnotte_id', $cagnotte->id)
            ->where('tondo_participants.statut_paiement', 'en_attente')
            ->where('users.compte_type', 'full')
            ->pluck('users.id')
            ->filter()
            ->values()
            ->all();

        if (empty($participantsEnAttente)) {
            return response()->json(['message' => 'Aucun participant en attente de paiement.']);
        }

        app(OneSignalService::class)->notify(
            userIds: $participantsEnAttente,
            titleFr: 'Rappel de cotisation',
            bodyFr:  "Votre cotisation pour « {$cagnotte->titre} » est en attente.",
            data:    ['type' => 'rappel_paiement', 'cagnotte_id' => $cagnotte->id],
        );

        return response()->json([
            'message'      => 'Rappel envoyé.',
            'destinataires' => count($participantsEnAttente),
        ]);
    }

    private function serialize(TondoCagnotte $c): array
    {
        return [
            'id'                    => $c->id,
            'reference'             => $c->reference,
            'titre'                 => $c->titre,
            'type'                  => $c->type,
            'statut'                => $c->statut,
            'numero_retrait_masque' => $c->numero_retrait_masque,
            'montant_collecte'      => (int) $c->montant_collecte,
            'montant_beneficiaire'  => $c->montant_beneficiaire,
            'montant_avec_frais'    => $c->montant_avec_frais,
            'total_a_envoyer'       => $c->total_a_envoyer,
            'montant_cible'         => $c->montant_cible,
            'date_fin'              => $c->date_fin?->toIso8601String(),
            'montant_par_cycle'     => $c->montant_par_cycle,
            'periodicite'           => $c->periodicite,
            'intervalle'            => $c->intervalle,
            'jour_semaine'          => $c->jour_semaine,
            'jour_mois'             => $c->jour_mois,
            'nombre_participants'   => $c->nombre_participants,
            'nombre_inscrits'       => (int) $c->nombre_inscrits,
            'nombre_splits'         => $c->nombre_splits,
            'nombre_envois'         => $c->nombre_envois,
            'date_creation'         => $c->date_creation?->toIso8601String(),
            'date_demarrage'        => $c->date_demarrage?->toIso8601String(),
        ];
    }

    /** @deprecated Migré vers TontineService::prochaineDate(). */
    private function calculerProchaineDate(TondoCagnotte $c, int $cyclesCompletes): ?string
    {
        if ($c->statut !== 'en_cours' || ! $c->periodicite) {
            return null;
        }

        $intervalle = (int) ($c->intervalle ?? 1);
        $now        = now()->startOfDay();

        if ($c->periodicite === 'hebdomadaire' && $c->jour_semaine) {
            $dowMap = [
                'lundi'    => Carbon::MONDAY,    'mardi'    => Carbon::TUESDAY,
                'mercredi' => Carbon::WEDNESDAY, 'jeudi'    => Carbon::THURSDAY,
                'vendredi' => Carbon::FRIDAY,    'samedi'   => Carbon::SATURDAY,
                'dimanche' => Carbon::SUNDAY,
            ];
            $targetDow = $dowMap[$c->jour_semaine] ?? Carbon::FRIDAY;

            if ($c->date_demarrage) {
                // Ancrage sur date_demarrage → suivi exact de tous les cycles.
                $debut          = Carbon::parse($c->date_demarrage)->startOfDay();
                $premierRetrait = $debut->copy();
                if ($premierRetrait->dayOfWeek !== $targetDow) {
                    $premierRetrait->next($targetDow);
                }
                $prochainRetrait = $premierRetrait->copy()
                    ->addWeeks($cyclesCompletes * $intervalle);
                // Si ce retrait est déjà passé (payout non encore enregistré),
                // avancer d'un cycle pour rester cohérent.
                if ($prochainRetrait->lt($now)) {
                    $prochainRetrait->addWeeks($intervalle);
                }
                return $prochainRetrait->toDateString();
            }

            // Fallback : prochaine occurrence depuis aujourd'hui.
            $prochain = $now->copy();
            if ($prochain->dayOfWeek !== $targetDow) {
                $prochain->next($targetDow);
            }
            return $prochain->toDateString();
        }

        if ($c->periodicite === 'mensuelle' && $c->jour_mois) {
            $jourMois = (int) $c->jour_mois;

            if ($c->date_demarrage) {
                // Ancrage sur date_demarrage → suivi exact de tous les cycles.
                $debut          = Carbon::parse($c->date_demarrage)->startOfDay();
                $premierRetrait = $debut->copy()->setDay($jourMois);
                if ($premierRetrait->lt($debut)) {
                    $premierRetrait->addMonths($intervalle);
                }
                $prochainRetrait = $premierRetrait->copy()
                    ->addMonths($cyclesCompletes * $intervalle);
                if ($prochainRetrait->lt($now)) {
                    $prochainRetrait->addMonths($intervalle);
                }
                return $prochainRetrait->toDateString();
            }

            // Fallback : prochaine occurrence depuis aujourd'hui.
            $prochain = $now->day <= $jourMois
                ? $now->copy()->setDay($jourMois)
                : $now->copy()->addMonths($intervalle)->setDay($jourMois);
            return $prochain->toDateString();
        }

        return null;
    }

    /**
     * Retourne le prochain participant à recevoir la mise (cyclesCompletes + 1).
     */
    private function calculerProchainBeneficiaire(array $participants, int $cyclesCompletes): ?array
    {
        $prochainOrdre = $cyclesCompletes + 1;

        foreach ($participants as $p) {
            if ((int) ($p['ordre_passage'] ?? 0) === $prochainOrdre) {
                return [
                    'nom'    => $p['nom'],
                    'prenom' => $p['prenom'],
                    'ordre'  => $prochainOrdre,
                    'is_me'  => (bool) ($p['is_me'] ?? false),
                ];
            }
        }

        return null;
    }
}
