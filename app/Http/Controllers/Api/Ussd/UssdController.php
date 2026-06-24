<?php

namespace App\Http\Controllers\Api\Ussd;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use App\Services\WhatsApp\CotisationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Canal USSD — deux points d'entrée pour permettre à un cotisant
 * de cotiser à une cagnotte/tontine depuis un menu USSD opérateur.
 *
 * Flux attendu côté passerelle USSD :
 *   1. Opérateur saisit le code de la cagnotte  → appel à infos()
 *      La passerelle récupère le type et le montant attendu,
 *      et renvoie le MSISDN de l'abonné dans la réponse.
 *   2. Opérateur saisit le montant               → appel à cotiser()
 *      Validation selon le type puis lancement du paiement Mobile Money.
 *
 * Sécurité : toutes les requêtes doivent porter l'entête
 *   X-Ussd-Secret: <valeur de USSD_SECRET dans .env>
 * Comparaison avec hash_equals() pour résister aux attaques temporelles.
 */
class UssdController extends Controller
{
    public function __construct(
        private readonly CotisationService $cotisationSvc,
    ) {}

    // ════════════════════════════════════════════════════════════════════════
    //  API 1 — Récupérer les infos d'une cagnotte par sa référence
    // ════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/ussd/cagnotte/{reference}?msisdn=+241770000000
     *
     * Recherche la cagnotte par son identifiant court (ex : "4821").
     * Retourne le type, le titre, le montant à cotiser, et renvoie le MSISDN
     * normalisé (la passerelle USSD le passe en query param, on le confirme).
     *
     * Codes HTTP :
     *   200 → cagnotte trouvée et active
     *   401 → secret USSD manquant ou incorrect
     *   404 → référence inconnue
     *   422 → cagnotte non active (brouillon ou clôturée)
     *
     * @param  string  $reference  Identifiant numérique court de la cagnotte.
     */
    public function infos(Request $request, string $reference): JsonResponse
    {
        // Vérification du secret USSD avant tout traitement
        if (! $this->verifierSecret($request)) {
            return response()->json(['erreur' => 'Non autorisé.'], 401);
        }

        // Résolution du project_id depuis la base (même pattern que BotService)
        $projectId = $this->projectId();

        // Recherche par référence numérique courte
        $cagnotte = TondoCagnotte::where('project_id', $projectId)
            ->where('reference', $reference)
            ->first();

        if (! $cagnotte) {
            return response()->json([
                'erreur' => "Code inconnu : {$reference}. Vérifiez le numéro de la tontine.",
            ], 404);
        }

        // Seules les cagnottes actives acceptent des cotisations
        if ($cagnotte->statut !== 'en_cours') {
            return response()->json([
                'erreur' => "Cette cagnotte n'est pas active (statut : {$cagnotte->statut}).",
            ], 422);
        }

        // Le MSISDN peut arriver en query param (GET) ou en body (certaines passerelles POST déguisées)
        $msisdn = $this->normaliserMsisdn(
            $request->query('msisdn', $request->input('msisdn', ''))
        );

        // Construction de la réponse commune
        $reponse = [
            'reference'     => $cagnotte->reference,
            'titre'         => $cagnotte->titre,
            'type'          => $cagnotte->type,
            'statut'        => $cagnotte->statut,
            'numero_client' => $msisdn,
        ];

        if ($cagnotte->type === 'tontine_periodique') {
            // Tontine : montant fixe imposé, on l'indique clairement
            $reponse['montant_par_cycle'] = (int) $cagnotte->montant_par_cycle;
            $reponse['message']           = "Tontine « {$cagnotte->titre} ». "
                . "Montant à cotiser : {$cagnotte->montant_par_cycle} FCFA.";
        } else {
            // Cagnotte ouverte : montant libre, plancher 100 FCFA
            $reponse['montant_min'] = 100;
            $reponse['message']     = "Cagnotte « {$cagnotte->titre} ». "
                . 'Saisissez le montant (minimum 100 FCFA).';
        }

        return response()->json($reponse);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  API 2 — Initier la cotisation
    // ════════════════════════════════════════════════════════════════════════

    /**
     * POST /api/ussd/cotiser
     *
     * Corps attendu (JSON) :
     *   reference  string  — identifiant numérique de la cagnotte (ex : "4821")
     *   msisdn     string  — numéro Mobile Money du cotisant (E.164 ou local)
     *   montant    integer — montant en FCFA
     *
     * Règles de validation du montant :
     *   - Tontine  → doit être EXACTEMENT égal à montant_par_cycle
     *   - Cagnotte → libre, mais >= 100 FCFA
     *
     * Codes HTTP :
     *   200 → paiement initié (en attente de confirmation Mobile Money)
     *   401 → secret USSD incorrect
     *   404 → cagnotte inconnue
     *   422 → montant invalide ou cagnotte non active
     *   500 → erreur interne lors du lancement du paiement
     */
    public function cotiser(Request $request): JsonResponse
    {
        // Vérification du secret USSD
        if (! $this->verifierSecret($request)) {
            return response()->json(['erreur' => 'Non autorisé.'], 401);
        }

        // Validation des champs obligatoires
        $validated = $request->validate([
            'reference' => 'required|string',
            'msisdn'    => 'required|string',
            'montant'   => 'required|integer|min:1',
        ]);

        $projectId = $this->projectId();
        $reference = $validated['reference'];
        $montant   = (int) $validated['montant'];
        $msisdn    = $this->normaliserMsisdn($validated['msisdn']);

        // Recherche de la cagnotte
        $cagnotte = TondoCagnotte::where('project_id', $projectId)
            ->where('reference', $reference)
            ->first();

        if (! $cagnotte) {
            return response()->json([
                'erreur' => "Code inconnu : {$reference}.",
            ], 404);
        }

        if ($cagnotte->statut !== 'en_cours') {
            return response()->json([
                'erreur' => "Cette cagnotte n'est pas active.",
            ], 422);
        }

        // ── Validation du montant selon le type ─────────────────────────────

        if ($cagnotte->type === 'tontine_periodique') {
            // Montant strict : le cotisant doit envoyer exactement le montant du cycle
            $attendu = (int) $cagnotte->montant_par_cycle;
            if ($montant !== $attendu) {
                return response()->json([
                    'erreur'  => "Montant incorrect. La tontine « {$cagnotte->titre} » "
                        . "impose exactement {$attendu} FCFA par cotisation.",
                    'attendu' => $attendu,
                    'recu'    => $montant,
                ], 422);
            }
        } else {
            // Cagnotte ouverte : plancher à 100 FCFA, pas de plafond côté validation
            if ($montant < 100) {
                return response()->json([
                    'erreur'      => 'Le montant minimum est 100 FCFA.',
                    'montant_min' => 100,
                    'recu'        => $montant,
                ], 422);
            }
        }

        // ── Résolution du compte cotisant ───────────────────────────────────
        // Cherche un compte existant par suffixe de numéro, crée un compte
        // light USSD si aucun n'existe (même logique que le bot WhatsApp).
        $user = $this->trouverOuCreerUtilisateur($msisdn, $projectId);

        // ── Lancement du paiement ────────────────────────────────────────────
        try {
            $resultat = $this->cotisationSvc->initier($user, $cagnotte, $montant);
        } catch (\Throwable $e) {
            return response()->json([
                'erreur' => 'Erreur lors du lancement du paiement : ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'succes'      => true,
            'message'     => 'Paiement initié. Confirmez la demande sur votre téléphone.',
            'transaction' => $resultat,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  Helpers privés
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Vérifie que l'entête X-Ussd-Secret correspond à la variable USSD_SECRET
     * configurée dans .env. Utilise hash_equals() pour éviter les timing attacks.
     */
    private function verifierSecret(Request $request): bool
    {
        $attendu = config('tondo.ussd_secret', '');
        $recu    = (string) $request->header('X-Ussd-Secret', '');

        // Si aucun secret n'est configuré en production, on bloque par défaut
        if ($attendu === '') {
            return false;
        }

        return hash_equals($attendu, $recu);
    }

    /**
     * Résout le project_id Tondo en lisant la table `projects`.
     * Résultat mis en cache statique pour la durée de la requête.
     */
    private function projectId(): string
    {
        static $id = null;
        if ($id === null) {
            $id = DB::table('projects')->where('slug', 'tondo')->value('id') ?? '';
        }
        return $id;
    }

    /**
     * Normalise un numéro de téléphone gabonais en format E.164 (+241XXXXXXXXX).
     *
     * Accepte les formes : 077XXXXXXX, 0077XXXXXXX, 241XXXXXXXXX, +241XXXXXXXXX.
     * Les numéros non reconnus sont retournés best-effort avec un + devant.
     */
    private function normaliserMsisdn(string $raw): string
    {
        // Supprime tout sauf les chiffres
        $chiffres = preg_replace('/\D/', '', $raw);

        // Déjà au format international complet : 241 + ≥ 8 chiffres
        if (str_starts_with($chiffres, '241') && strlen($chiffres) >= 11) {
            return '+' . $chiffres;
        }

        // Préfixe local 0 suivi de 9 chiffres → +241 + 9 chiffres
        if (str_starts_with($chiffres, '0') && strlen($chiffres) >= 10) {
            return '+241' . substr($chiffres, 1);
        }

        // Cas générique : on préfixe + best-effort
        return '+' . $chiffres;
    }

    /**
     * Cherche un compte TondoUser par suffixe de numéro (9 derniers chiffres)
     * pour tolérer les variantes de préfixe (+241 vs 0 vs 00241).
     *
     * Si aucun compte n'existe, crée un compte USSD minimal (light) afin que
     * la transaction soit rattachée à un utilisateur identifiable. Ce compte
     * peut être enrichi plus tard si l'utilisateur s'inscrit via l'app.
     *
     * @param  string $msisdnE164  Numéro normalisé en format E.164.
     * @param  string $projectId   UUID du projet Tondo.
     */
    private function trouverOuCreerUtilisateur(string $msisdnE164, string $projectId): TondoUser
    {
        // Recherche par les 9 derniers chiffres du numéro
        $suffixe  = substr(preg_replace('/\D/', '', $msisdnE164), -9);
        $existant = TondoUser::where('project_id', $projectId)
            ->where('numero', 'like', "%{$suffixe}")
            ->first();

        if ($existant) {
            return $existant;
        }

        // Création d'un compte light USSD — même structure qu'un compte WhatsApp light
        $user                 = new TondoUser();
        $user->id             = (string) Str::uuid();
        $user->project_id     = $projectId;
        $user->nom            = 'USSD';
        $user->prenom         = 'Utilisateur';
        $user->numero         = $msisdnE164;
        $user->indicatif      = '241';
        $user->pays           = 'GA';
        $user->operateur      = null;
        $user->date_naissance = '1900-01-01';   // placeholder — enrichi à l'inscription app
        $user->kyc_valide     = false;
        $user->type_client    = 'particulier';
        $user->save();

        return $user;
    }
}
