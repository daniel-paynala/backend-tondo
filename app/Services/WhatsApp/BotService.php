<?php

namespace App\Services\WhatsApp;

use App\Models\TondoCagnotte;
use App\Models\TondoPaiementEnAttente;
use App\Models\TondoUser;
use App\Services\ReceiptService;
use App\Services\OtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moteur conversationnel du bot WhatsApp Tonji.
 *
 * Machine à états pilotée par SessionService. Chaque message entrant
 * est traité par traiter() qui lit l'étape courante et dispatch vers
 * le handler correspondant. L'état est persisté en cache Redis (30 min).
 *
 * Ordre de priorité dans traiter() :
 *   1. Deep link "TONJI [ref]" → contourne toute session existante
 *   2. Mot-clé de retour (#, menu, retour…) → reset + menu
 *   3. Aucune session / premier message → premiereArrivee()
 *   4. match() sur l'étape courante → handler spécifique
 *
 * POSITIONNEMENT « CAGNOTTE D'ABORD » (flag config/tondo.php → tontines_actives,
 * défaut false ; pendant de kTontinesActives côté Flutter et TONTINES_ACTIVES
 * côté web — les trois canaux doivent rester alignés) :
 *   • Flag OFF : le menu et tout le lexique sont 100 % cagnotte, et l'étape
 *     'creer.type' est SAUTÉE (demarrerCreer part directement sur une cagnotte).
 *     C'est le seul endroit où une tontine peut naître → aucune tontine nouvelle.
 *   • Les handlers tontine ci-dessous ne sont PAS supprimés : ils restent en
 *     place, gèrent les tontines déjà en base, et redeviennent atteignables en
 *     passant TONJI_TONTINES_ACTIVES=true.
 *   Le diagramme ci-dessous décrit le parcours COMPLET (flag ON).
 *
 * Diagramme des états :
 *
 *  [aucune session] ──► MENU
 *
 *  MENU ──► 1 ──► cotiser.ref
 *                     │
 *                     ├─ tontine ──► cotiser.numero
 *                     │                  │
 *                     │                  ├─ non-membre ──► "pas inscrit" + menu
 *                     │                  └─ membre ──► [push] ──► cotiser.attente
 *                     │
 *                     └─ cotisation ──► cotiser.montant ──► cotiser.numero
 *                                                               │
 *                                                               ├─ connu ──► [push] ──► cotiser.attente
 *                                                               └─ inconnu ──► KYC Airtel → compte light + [push] ──► cotiser.attente
 *
 *        ──► 2 ──► rejoindre.ref ──► rejoindre.numero
 *                     ├─ inconnu    ──► KYC Airtel → compte light → inscription ──► menu
 *                     └─ connu      ──► inscription ──► menu
 *        ──► 3 ──► creer.type ──► creer.cotisation.* | creer.tontine.* ──► creer.recap
 *        ──► 4 ──► gerer.numero ──► gerer.otp ──► gerer.liste ──► gerer.cagnotte | gerer.tontine
 *        ──► 5 ──► aide
 *
 *  cotiser.attente ──► OK ──► vérif statut ──► reçu / échec / toujours en cours
 *
 * Deep link :
 *   "TONJI 315167" → tontine non démarrée → rejoindre.numero
 *                 → tontine démarrée      → cotiser.numero (montant fixe)
 *                 → cagnotte ouverte      → cotiser.montant
 */
class BotService
{
    public function __construct(
        private SessionService       $session,
        private CotisationService    $cotisationSvc,
        private ReceiptService       $receiptSvc,
        private CreerCagnotteService $creerCagnotteSvc,
        private GererCagnotteService $gererCagnotteSvc,
        private OtpService           $otpService,
        private TwilioSenderService  $twilio,
    ) {}

    // ── Point d'entrée ────────────────────────────────────────────────────────

    /**
     * Point d'entrée principal — traite un message WhatsApp entrant.
     *
     * Appelé par le contrôleur webhook pour chaque message reçu de Twilio.
     * Retourne soit une chaîne (message texte), soit un tableau à deux éléments
     * [texte, urlPdf] lorsqu'un reçu PDF doit être joint en Media.
     *
     * Ordre de traitement (priorité décroissante) :
     *   1. Deep link "TONJI XXXXXX" → bypasse la session, flux direct
     *   2. Mot-clé de retour (#, menu…) → reset session, afficher menu
     *   3. Session nulle ou message vide → premiereArrivee()
     *   4. Dispatch sur l'étape de session → handler métier
     *
     * @param  string $numero  Numéro E.164 de l'expéditeur (ex : +24177123456)
     * @param  string $texte   Contenu du message reçu (avant trim)
     * @return string|array{0:string,1:string}
     */
    public function traiter(string $numero, string $texte): string|array
    {
        $texte = trim($texte);
        $etape = $this->session->etape($numero);

        // ── 1. Deep link TONJI [ref] — contourne l'état de session ───────────
        // Format attendu : "TONJI 315167" (insensible à la casse, 4-6 chiffres)
        if (preg_match('/^TONJI\s+(\d{4,6})$/i', $texte, $m)) {
            return $this->handleDeepLink($numero, $m[1]);
        }

        // ── 2. Retour menu explicite — n'importe quelle étape ────────────────
        if ($this->estRetourMenu($texte)) {
            $this->session->reset($numero);
            return $this->afficherMenu($numero);
        }

        // ── 3. Aucune session ou message vide → premier contact ──────────────
        if ($etape === null || $texte === '') {
            return $this->premiereArrivee($numero);
        }

        // ── 4. Dispatch sur l'étape de session ───────────────────────────────
        return match (true) {
            $etape === 'menu'                  => $this->handleMenu($numero, $texte),
            $etape === 'cotiser.ref'            => $this->handleCotiserRef($numero, $texte),
            $etape === 'cotiser.montant'        => $this->handleCotiserMontant($numero, $texte),
            $etape === 'cotiser.numero'         => $this->handleCotiserNumero($numero, $texte),
            $etape === 'cotiser.attente'        => $this->handleCotiserAttente($numero, $texte),
            $etape === 'rejoindre.ref'          => $this->handleRejoindreRef($numero, $texte),
            $etape === 'rejoindre.numero'       => $this->handleRejoindreNumero($numero, $texte),
            str_starts_with($etape, 'creer.')  => $this->routerCreer($numero, $etape, $texte),
            str_starts_with($etape, 'gerer.')  => $this->routerGerer($numero, $etape, $texte),
            default                             => $this->afficherMenu($numero),  // étape inconnue → menu
        };
    }

    // ── Première arrivée ──────────────────────────────────────────────────────

    /**
     * Initialise la session au menu principal lors du premier contact.
     *
     * @param  string $numero  Numéro E.164 de l'expéditeur
     * @return string          Texte du menu principal
     */
    private function premiereArrivee(string $numero): string
    {
        $this->session->set($numero, 'menu');
        return $this->afficherMenu($numero);
    }

    // ── Menu principal ────────────────────────────────────────────────────────

    /**
     * Réinitialise la session et retourne un message d'erreur suivi du menu.
     * Méthode de convenance pour les cas d'erreur récupérables.
     *
     * @param  string $numero   Numéro E.164
     * @param  string $message  Message d'erreur à afficher avant le menu
     * @return string           Erreur + menu concaténés
     */
    private function erreurEtMenu(string $numero, string $message): string
    {
        $this->session->reset($numero);
        return $message . "\n\n" . $this->afficherMenu($numero);
    }

    /**
     * Affiche le menu principal et positionne la session à l'étape 'menu'.
     * Méthode à double rôle : initialise l'état ET retourne le texte.
     * Appelée depuis premiereArrivee(), retourMenu, et fin de parcours réussi.
     *
     * @param  string $numero  Numéro E.164
     * @return string          Texte du menu principal formaté WhatsApp
     */
    private function afficherMenu(string $numero): string
    {
        $this->session->set($numero, 'menu');

        return "🎉 *Bienvenue sur Tonji !*\n\n" . $this->corpsMenu();
    }

    /**
     * Corps du menu principal (question + 5 options), SANS toucher à la session.
     *
     * Source unique de vérité du libellé du menu : afficherMenu() l'utilise après
     * avoir positionné la session, et recu() le réutilise pour proposer la suite
     * après un paiement — recu() étant aussi appelée par le scheduler, elle n'a
     * pas toujours de session sous la main, d'où la séparation.
     *
     * @return string  Les 5 options, formatées WhatsApp
     */
    private function corpsMenu(): string
    {
        // Positionnement « Cagnotte d'abord » : tant que les tontines sont
        // désactivées, le menu ne parle que de cagnottes. L'ancien libellé
        // (mixte tontine/cagnotte) reste sous le flag, prêt à revenir.
        if (! $this->tontinesActives()) {
            return <<<TXT
            Que souhaitez-vous faire ?

            1️⃣  *Cotiser* à une cagnotte
            2️⃣  *Rejoindre* une cagnotte
            3️⃣  *Créer* une cagnotte
            4️⃣  *Gérer* mes cagnottes
            5️⃣  *Aide* & support

            _Tapez le numéro de votre choix._
            TXT;
        }

        return <<<TXT
        Que souhaitez-vous faire ?

        1️⃣  *Cotiser*
        2️⃣  *Rejoindre* une tontine
        3️⃣  *Créer* (Tontine ou Cagnotte)
        4️⃣  *Gérer* (Tontine ou Cagnotte)
        5️⃣  *Aide* & support

        _Tapez le numéro de votre choix._
        TXT;
    }

    /**
     * Les parcours TONTINE sont-ils actifs ?
     *
     * Pendant du `kTontinesActives` Flutter et du `TONTINES_ACTIVES` web.
     * Piloté par TONJI_TONTINES_ACTIVES (.env) — voir config/tondo.php.
     *
     * @return bool  true si la tontine est proposée à l'utilisateur
     */
    private function tontinesActives(): bool
    {
        return (bool) config('tondo.tontines_actives', false);
    }

    /**
     * Dispatch du menu principal selon le choix de l'utilisateur.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Chiffre saisi par l'utilisateur (1-5)
     * @return string
     */
    private function handleMenu(string $numero, string $texte): string
    {
        return match (trim($texte)) {
            '1'     => $this->demarrerCotiser($numero),
            '2'     => $this->demarrerRejoindre($numero),
            '3'     => $this->demarrerCreer($numero),
            '4'     => $this->demarrerGerer($numero),
            '5'     => $this->afficherAide($numero),
            default => $this->afficherMenu($numero),  // saisie invalide → réafficher le menu
        };
    }

    // ── 1 — Cotiser : référence ───────────────────────────────────────────────

    /**
     * Démarre le flux de cotisation : positionne la session à 'cotiser.ref'
     * et demande le numéro de référence de la tontine/cagnotte.
     *
     * @param  string $numero  Numéro E.164
     * @return string
     */
    private function demarrerCotiser(string $numero): string
    {
        $this->session->set($numero, 'cotiser.ref');
        return <<<TXT
        💰 *Cotiser*

        Entrez le *code de la cagnotte*
        (6 chiffres, fourni par l'organisateur).

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Traite la saisie de la référence de tontine/cagnotte.
     *
     * Vérifie l'existence et le statut de la cagnotte, puis :
     *   - Tontine non complète → erreur (les paiements sont bloqués tant que tous
     *     les membres ne sont pas inscrits). Le +1 est le créateur qui est
     *     dans tondo_participants mais PAS comptabilisé dans nombre_inscrits.
     *   - Tontine complète avec montant fixe → saute l'étape 'cotiser.montant',
     *     va directement à 'cotiser.numero'.
     *   - Cagnotte ouverte → demande le montant ('cotiser.montant').
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Référence saisie (peut contenir des espaces ou lettres)
     * @return string
     */
    private function handleCotiserRef(string $numero, string $texte): string
    {
        // Extraire uniquement les chiffres (l'utilisateur peut saisir "N°315167")
        $ref      = preg_replace('/\D/', '', $texte);
        $cagnotte = $ref ? TondoCagnotte::where('reference', $ref)->first() : null;

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Code de cagnotte *N°{$ref}* introuvable.\nVérifiez et réessayez.");
        }

        if ($cagnotte->statut === 'cloturee') {
            return "❌ La cagnotte *{$cagnotte->titre}* est clôturée.\n\n#️⃣ _pour revenir en arrière_";
        }

        // Stocker les infos cagnotte dans la session pour les étapes suivantes
        $this->session->set($numero, 'cotiser.montant', [
            'reference'         => $ref,
            'cagnotte_id'       => $cagnotte->id,
            'cagnotte_titre'    => $cagnotte->titre,
            'type'              => $cagnotte->type,
            'project_id'        => $cagnotte->project_id,
            'montant_par_cycle' => $cagnotte->montant_par_cycle,
        ]);

        // Tontine : bloquer si pas encore complète (attente de tous les membres)
        if ($cagnotte->type === 'tontine_periodique') {
            // +1 : le créateur compte dans tondo_participants mais PAS dans nombre_inscrits
            $manquants = ($cagnotte->nombre_participants ?? 0) - (($cagnotte->nombre_inscrits ?? 0) + 1);
            if ($manquants > 0) {
                return $this->erreurEtMenu($numero, <<<TXT
                ⏳ *La tontine n'a pas encore démarré.*

                Il manque encore *{$manquants} membre(s)* avant le lancement.
                Les paiements seront disponibles une fois tous les membres inscrits.
                TXT);
            }
        }

        // Tontine → montant fixe connu : sauter l'étape montant, demander directement le numéro
        if ($cagnotte->type === 'tontine_periodique' && $cagnotte->montant_par_cycle) {
            $fmt = number_format((int) $cagnotte->montant_par_cycle, 0, ',', ' ');
            // Passer directement à 'cotiser.numero' en pré-remplissant le montant
            $this->session->set($numero, 'cotiser.numero', [
                'reference'      => $ref,
                'cagnotte_id'    => $cagnotte->id,
                'cagnotte_titre' => $cagnotte->titre,
                'type'           => $cagnotte->type,
                'project_id'     => $cagnotte->project_id,
                'montant'        => (int) $cagnotte->montant_par_cycle,
            ]);

            return <<<TXT
            ✅ *{$cagnotte->titre}* · N°{$ref}
            Type : Tontine · Montant fixe : *{$fmt} FCFA*

            Entrez votre *numéro de téléphone* Mobile Money
            (format : *0XXXXXXXX*).

            #️⃣ _pour revenir en arrière_
            TXT;
        }

        // Cotisation → demander le montant
        $this->session->set($numero, 'cotiser.montant', [
            'reference'      => $ref,
            'cagnotte_id'    => $cagnotte->id,
            'cagnotte_titre' => $cagnotte->titre,
            'type'           => $cagnotte->type,
            'project_id'     => $cagnotte->project_id,
        ]);

        return <<<TXT
        ✅ *{$cagnotte->titre}* · N°{$ref}
        Type : Cagnotte

        Quel *montant* souhaitez-vous cotiser ?
        _(minimum 100 FCFA)_

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    // ── 1 — Cotiser : montant (cotisation uniquement) ─────────────────────────

    /**
     * Traite la saisie du montant de cotisation (cagnottes ouvertes uniquement).
     * Les tontines sautent cette étape car le montant est fixe (voir handleCotiserRef).
     * Limites : minimum 100 FCFA, maximum 500 000 FCFA par transaction.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Montant saisi (peut contenir des espaces, lettres)
     * @return string
     */
    private function handleCotiserMontant(string $numero, string $texte): string
    {
        $montant = (int) preg_replace('/\D/', '', $texte);

        if ($montant < 100) {
            return "⚠️ Montant minimum : *100 FCFA*.\nEntrez un montant valide, ou #️⃣ pour revenir en arrière.";
        }

        if ($montant > 500_000) {
            return "⚠️ Montant trop élevé.\nEntrez un montant valide, ou #️⃣ pour revenir en arrière.";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'cotiser.numero', array_merge($data, ['montant' => $montant]));

        return <<<TXT
        💵 Montant : *{$montant} FCFA*

        Entrez votre *numéro de téléphone* Mobile Money
        (format : *0XXXXXXXX*).

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    // ── 1 — Cotiser : numéro de téléphone ────────────────────────────────────

    /**
     * Traite la saisie du numéro Mobile Money du cotisant.
     *
     * Comportement selon le type :
     *   - Tontine : vérifie que le numéro est bien inscrit comme membre.
     *     Si non inscrit → erreur (doit passer par "Rejoindre" d'abord).
     *   - Cotisation : si l'utilisateur est connu → paiement direct.
     *     Si inconnu → crée un compte light anonyme (nom/prénom vides)
     *     puis lance le paiement sans collecter l'identité.
     *
     * @param  string $numero  Numéro WhatsApp de l'expéditeur (E.164)
     * @param  string $texte   Numéro Mobile Money saisi
     * @return string|array{0:string,1:string}
     */
    private function handleCotiserNumero(string $numero, string $texte): string|array
    {
        $numeroSaisi = $this->normaliserNumero($texte);

        if (! $numeroSaisi) {
            return "⚠️ Numéro invalide.\nFormat attendu : *0XXXXXXXX*\n\n#️⃣ _pour revenir en arrière_";
        }

        $data     = $this->session->data($numero);
        $type     = $data['type'] ?? '';
        $projectId = $data['project_id'] ?? $this->tondoProjectId();

        // Rechercher l'utilisateur par suffixe (9 derniers chiffres, tolérant +241/0)
        $user = $this->utilisateurParNumero($numeroSaisi, $projectId);

        // ── Tontine : vérifier que l'utilisateur est bien un membre inscrit ──
        if ($type === 'tontine_periodique') {
            $estMembre = $user && DB::table(project_table('participants'))
                ->where('cagnotte_id', $data['cagnotte_id'])
                ->where('user_id', $user->id)
                ->exists();

            if (! $estMembre) {
                return $this->erreurEtMenu($numero, <<<TXT
                ❌ *Vous n'êtes pas encore inscrit à cette tontine.*

                Rejoignez-la d'abord en choisissant l'option *2️⃣* du menu.
                TXT);
            }

            // Membre confirmé → lancer le push Airtel
            return $this->lancerPaiement($numero, $user, $data, $numeroSaisi);
        }

        // ── Cotisation : utilisateur connu → paiement direct ─────────────────
        if ($user) {
            return $this->lancerPaiement($numero, $user, $data, $numeroSaisi);
        }

        // ── Cotisation : inconnu → KYC Airtel (récupère nom/prénom) puis compte LIGHT ──
        // Le cotisant doit avoir un compte Airtel Money pour payer ; on récupère donc
        // son identité automatiquement (comme le mobile) au lieu de la demander, et on
        // l'enregistre en compte LIGHT (pas de promotion en full, pas d'OTP).
        $kyc = $this->verifierKycAirtel($numeroSaisi);
        if ($kyc['bloque']) {
            return $kyc['message']
                . "\n\nEntrez un *numéro Airtel Money* (format *0XXXXXXXX*) :"
                . "\n\n#️⃣ _pour revenir en arrière_";
        }

        $user = $this->cotisationSvc->creerCompteLight(
            nom: $kyc['nom'],
            prenom: $kyc['prenom'],
            numeroE164: $numeroSaisi,
            projectId: $projectId,
        );

        return $this->lancerPaiement($numero, $user, $data, $numeroSaisi);
    }

    // ── 1 — Cotiser : initier le paiement et attendre ────────────────────────

    /**
     * Enveloppe publique de _lancerPaiement() avec gestion des exceptions.
     *
     * En cas d'erreur inattendue : reset la session et retourne un message générique.
     * Le vrai travail est dans _lancerPaiement() (pattern façade/wrapper).
     *
     * @param  string    $numero       Numéro WhatsApp de l'expéditeur
     * @param  TondoUser $user         Compte utilisateur (peut être light/anonyme)
     * @param  array     $data         Données de session (cagnotte_id, montant…)
     * @param  string    $numeroPayeur Numéro Mobile Money sur lequel débiter
     * @return string|array{0:string,1:string}
     */
    private function lancerPaiement(string $numero, TondoUser $user, array $data, string $numeroPayeur): string|array
    {
        try {
            return $this->_lancerPaiement($numero, $user, $data, $numeroPayeur);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('WhatsApp lancerPaiement exception', [
                'message' => $e->getMessage(),
                'line'    => $e->getLine(),
                'file'    => $e->getFile(),
            ]);
            $this->session->reset($numero);
            return "❌ Une erreur technique est survenue. Veuillez réessayer.\n\n#️⃣ _pour revenir en arrière_";
        }
    }

    /**
     * Implémentation réelle de l'initiation du paiement.
     *
     * Deux résultats possibles depuis CotisationService::initier() :
     *   - 'succes' (mock) → appelle recu() directement, reset session à 'menu'
     *   - 'initie' (Airtel push) → place la session à 'cotiser.attente'
     *     ET insère une ligne TondoPaiementEnAttente (surveillée par le scheduler
     *     toutes les minutes pour envoyer le reçu automatiquement si confirmé).
     *
     * Le numéro de paiement peut différer du numéro WhatsApp de l'expéditeur
     * (ex : payer pour quelqu'un d'autre depuis son propre WhatsApp).
     *
     * @param  string    $numero       Numéro WhatsApp expéditeur
     * @param  TondoUser $user         Compte utilisateur (ID utilisé pour le paiement)
     * @param  array     $data         Contexte session (cagnotte_id, montant…)
     * @param  string    $numeroPayeur Numéro Mobile Money effectivement débité
     * @return string|array{0:string,1:string}
     */
    private function _lancerPaiement(string $numero, TondoUser $user, array $data, string $numeroPayeur): string|array
    {
        $cagnotte = TondoCagnotte::find($data['cagnotte_id']);

        if (! $cagnotte) {
            $this->session->reset($numero);
            return "❌ Erreur : cagnotte introuvable.\n\n#️⃣ _pour revenir en arrière_";
        }

        // Cloner l'utilisateur pour remplacer son numéro par celui saisi (paiement tiers possible)
        $userPourPaiement         = clone $user;
        $userPourPaiement->numero = $numeroPayeur;

        $resultat = $this->cotisationSvc->initier($userPourPaiement, $cagnotte, (int) $data['montant']);

        if ($resultat['statut'] === 'erreur') {
            $this->session->reset($numero);
            return "❌ Erreur lors de l'initiation du paiement : {$resultat['message']}\n\n#️⃣ _pour revenir en arrière_";
        }

        $prenom     = ucfirst(mb_strtolower($user->prenom));
        $salut      = $prenom ? "Bonjour *{$prenom}* !" : 'Bonjour !';
        $montantFmt = number_format($data['montant'], 0, ',', ' ');

        // Paiement immédiat (mock ou opérateur non-Airtel) → reçu synchrone
        if ($resultat['statut'] === 'succes') {
            $this->session->set($numero, 'menu');
            return $this->recu($user, $cagnotte, $resultat);
        }

        // Paiement Airtel → push envoyé, attente de confirmation utilisateur
        $this->session->set($numero, 'cotiser.attente', [
            'trans_id'       => $resultat['trans_id'],
            'project_id'     => $cagnotte->project_id,
            'cagnotte_titre' => $cagnotte->titre,
            'reference'      => $cagnotte->reference,
            'montant'        => $data['montant'],
            'prenom'         => $prenom,
            'user_id'        => $user->id,
        ]);

        // Insérer dans la table de surveillance : le scheduler vérifie toutes les minutes
        // et envoie le reçu automatiquement si Airtel confirme avant que l'utilisateur tape "OK"
        TondoPaiementEnAttente::create([
            'trans_id'    => $resultat['trans_id'],
            'numero_wa'   => $numero,
            'project_id'  => $cagnotte->project_id,
            'cagnotte_ref' => $cagnotte->reference,
            'montant'     => (int) $data['montant'],
            'prenom'      => $prenom,
            'user_id'     => $user->id,
        ]);

        return <<<TXT
        ⏳ {$salut}

        Un message de confirmation a été envoyé sur votre téléphone *{$numeroPayeur}*.

        👉 *Validez le paiement de {$montantFmt} FCFA sur votre Mobile Money.*

        Vous recevrez la confirmation *automatiquement* dès validation (délai max 3 min).
        Tapez *OK* si vous souhaitez vérifier manuellement.

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    // ── 1 — Cotiser : vérification après push ────────────────────────────────

    /**
     * Gère l'étape d'attente après un push Airtel Money.
     *
     * L'utilisateur est en étape 'cotiser.attente'. Il doit taper "OK" pour
     * déclencher une vérification manuelle du statut. Si le statut est 'succes' :
     *   - Supprime la ligne TondoPaiementEnAttente (évite un double-envoi par le scheduler).
     *   - Génère le reçu PDF.
     *   - Retourne le message de confirmation avec reçu joint.
     *
     * Si l'utilisateur tape autre chose que "OK", on l'invite à patienter.
     *
     * @param  string $numero  Numéro WhatsApp expéditeur
     * @param  string $texte   Message reçu (attendu : "OK")
     * @return string|array{0:string,1:string}
     */
    private function handleCotiserAttente(string $numero, string $texte): string|array
    {
        $data    = $this->session->data($numero);
        $transId = $data['trans_id'] ?? null;

        if (! $transId) {
            // Session corrompue ou expirée
            $this->session->reset($numero);
            return $this->afficherMenu($numero);
        }

        if (strtolower(trim($texte)) !== 'ok') {
            // Tout message autre que "OK" → rappel d'attente
            return <<<TXT
            ⏳ Paiement en attente de confirmation.

            Validez le paiement sur votre Mobile Money puis tapez *OK*.

            #️⃣ _pour revenir en arrière_
            TXT;
        }

        $statut = $this->cotisationSvc->verifierStatut($transId, $data['project_id']);

        if ($statut === 'succes') {
            // Supprimer du suivi automatique AVANT d'envoyer le reçu
            // pour éviter que le scheduler envoie un second reçu en parallèle
            TondoPaiementEnAttente::where('trans_id', $transId)->delete();

            $this->session->set($numero, 'menu');
            $cagnotte = TondoCagnotte::where('reference', $data['reference'])->first();
            $user     = TondoUser::find($data['user_id']);

            $pdfUrl = null;
            try {
                $pdfUrl = $this->receiptSvc->generer($user, $cagnotte, [
                    'trans_id'    => $transId,
                    'montant_net' => (int) ($data['montant'] ?? 0),
                ], 'WhatsApp');
            } catch (\Throwable $e) {
                // Stack trace complète pour faciliter le diagnostic depuis les logs.
                Log::error('BotService: échec génération reçu (attente→succes)', [
                    'trans_id' => $transId,
                    'err'      => $e->getMessage(),
                    'class'    => get_class($e),
                    'file'     => $e->getFile() . ':' . $e->getLine(),
                    'trace'    => $e->getTraceAsString(),
                ]);
            }

            return $this->recu($user, $cagnotte, [
                'trans_id'    => $transId,
                'montant_net' => $data['montant'],
            ], pdfUrl: $pdfUrl);
        }

        if ($statut === 'echec') {
            $this->session->reset($numero);
            return <<<TXT
            ❌ *Paiement échoué ou refusé.*

            ⚠️ _Si vous constatez un problème, contactez-nous à support@tonji.ga._

            TXT . "\n" . $this->afficherMenu($numero);
        }

        // Toujours en cours
        return <<<TXT
        ⏳ Paiement toujours en cours de traitement.

        Attendez quelques secondes et tapez *OK* à nouveau.

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Génère le message de confirmation de paiement (reçu WhatsApp).
     *
     * Méthode publique : appelée depuis BotService après succès immédiat (mock),
     * depuis handleCotiserAttente() après confirmation manuelle, et directement
     * par le scheduler (CheckPaymentsJob) lorsqu'Airtel confirme automatiquement.
     * Le PDF est optionnel : joint en Media si l'URL est fournie.
     *
     * @param  TondoUser|null    $user     Cotisant (null si compte supprimé)
     * @param  TondoCagnotte|null $cagnotte Cagnotte concernée
     * @param  array             $resultat  Résultat de initier() (trans_id, montant_net…)
     * @param  string            $canal     Canal d'origine pour les logs (défaut 'WhatsApp')
     * @param  string|null       $pdfUrl    URL publique du reçu PDF joint en Media
     * @return string            Message de confirmation formaté WhatsApp
     */
    public function recu(?TondoUser $user, ?TondoCagnotte $cagnotte, array $resultat, string $canal = 'WhatsApp', ?string $pdfUrl = null): string
    {
        $montant = number_format((int) ($resultat['montant_net'] ?? 0), 0, ',', ' ');
        $titre   = $cagnotte ? $cagnotte->titre : '—';
        $ref     = $cagnotte ? 'N°' . $cagnotte->reference : '';
        $prenom  = $user ? ucfirst(mb_strtolower($user->prenom)) : '';
        $merci   = ($prenom && strtolower($prenom) !== 'anonyme') ? "Merci {$prenom} 🙏" : 'Merci 🙏';
        $ligneRecu = $pdfUrl ? "\n📄 *Votre reçu :* {$pdfUrl}" : '';

        // Le menu de fin réutilise corpsMenu() : sans ça, cette copie en dur
        // restait sur l'ancien libellé tontine après un paiement réussi.
        return <<<TXT
        ✅ *Paiement confirmé !*

        {$merci}
        Votre paiement de *{$montant} FCFA* pour *{$titre} {$ref}* a été enregistré.{$ligneRecu}

        ————————————————
        TXT . "\n" . $this->corpsMenu();
    }

    // ── 2 — Rejoindre ─────────────────────────────────────────────────────────

    /**
     * Démarre le flux d'inscription à une tontine ou cagnotte.
     *
     * @param  string $numero  Numéro E.164
     * @return string
     */
    private function demarrerRejoindre(string $numero): string
    {
        $this->session->set($numero, 'rejoindre.ref');
        return <<<TXT
        🤝 *Rejoindre une cagnotte*

        Entrez le *code de la cagnotte*
        (6 chiffres, fourni par l'organisateur).

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Traite la saisie de la référence lors du flow "Rejoindre".
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Référence saisie
     * @return string
     */
    private function handleRejoindreRef(string $numero, string $texte): string
    {
        $ref      = preg_replace('/\D/', '', $texte);
        $cagnotte = $ref ? TondoCagnotte::where('reference', $ref)->first() : null;

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Code de cagnotte *N°{$ref}* introuvable.\nVérifiez et réessayez.");
        }

        // Le type reste calculé : une tontine héritée (créée avant le passage en
        // « Cagnotte d'abord ») doit continuer à s'afficher correctement.
        $type = $cagnotte->type === 'tontine_periodique' ? 'Tontine' : 'Cagnotte';

        $this->session->set($numero, 'rejoindre.numero', [
            'cagnotte_id'  => $cagnotte->id,
            'cagnotte_ref' => $ref,
            'project_id'   => $cagnotte->project_id,
            'type'         => $cagnotte->type,
        ]);

        return <<<TXT
        🤝 *{$cagnotte->titre}* · N°{$ref}
        Type : {$type}

        Entrez votre *numéro de téléphone* Mobile Money
        (format : *0XXXXXXXX*).

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Traite la saisie du numéro Mobile Money lors du flow "Rejoindre".
     *
     * Vérifie :
     *   1. Que le numéro n'est pas déjà membre (idempotence).
     *   2. Pour les tontines : qu'il reste des places disponibles.
     *      Le +1 correspond au créateur qui est dans tondo_participants
     *      mais PAS comptabilisé dans nombre_inscrits.
     *
     * Si l'utilisateur est inconnu → KYC Airtel (récupère nom/prénom) → compte
     *   light → inscription directe. Numéro non-Airtel → erreur + redemande.
     * Si l'utilisateur est connu → inscription directe + retour menu.
     *
     * @param  string $numero  Numéro WhatsApp expéditeur
     * @param  string $texte   Numéro Mobile Money saisi
     * @return string
     */
    private function handleRejoindreNumero(string $numero, string $texte): string
    {
        $numeroSaisi = $this->normaliserNumero($texte);

        if (! $numeroSaisi) {
            return "⚠️ Numéro invalide.\nFormat attendu : *0XXXXXXXX*\n\n#️⃣ _pour revenir en arrière_";
        }

        $data      = $this->session->data($numero);
        $projectId = $data['project_id'] ?? $this->tondoProjectId();
        $cagnotte  = TondoCagnotte::find($data['cagnotte_id'] ?? null);

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Session expirée. Recommencez.");
        }

        $ref  = $data['cagnotte_ref'] ?? $cagnotte->reference;
        $user = $this->utilisateurParNumero($numeroSaisi, $projectId);

        // Vérifier si déjà inscrit (idempotence)
        if ($user) {
            $dejaMembre = DB::table(project_table('participants'))
                ->where('cagnotte_id', $cagnotte->id)
                ->where('user_id', $user->id)
                ->exists();

            if ($dejaMembre) {
                return $this->erreurEtMenu($numero, "ℹ️ Ce numéro est déjà membre de *{$cagnotte->titre}* (N°{$ref}).");
            }
        }

        // Tontine : vérifier places disponibles (+1 = créateur non compté dans nombre_inscrits)
        if ($cagnotte->type === 'tontine_periodique') {
            if (($cagnotte->nombre_inscrits ?? 0) + 1 >= ($cagnotte->nombre_participants ?? 0)) {
                return $this->erreurEtMenu($numero, "❌ *{$cagnotte->titre}* est complet.\nPlus aucune place disponible.");
            }
        }

        // Utilisateur connu → inscrire directement
        if ($user) {
            $this->inscrireMembre($user, $cagnotte);
            $prenom = ucfirst(mb_strtolower($user->prenom));
            $type   = $cagnotte->type === 'tontine_periodique' ? 'tontine' : 'cagnotte';

            return <<<TXT
            ✅ *Inscription confirmée !*

            Bienvenue *{$prenom}* ! Vous avez rejoint la {$type} *{$cagnotte->titre}* (N°{$ref}).

            TXT . "\n" . $this->afficherMenu($numero);
        }

        // Nouvel utilisateur → KYC Airtel (récupère nom/prénom) puis compte LIGHT
        // + inscription directe. On ne demande plus le nom/prénom.
        $kyc = $this->verifierKycAirtel($numeroSaisi);
        if ($kyc['bloque']) {
            return $kyc['message']
                . "\n\nEntrez un *numéro Airtel Money* (format *0XXXXXXXX*) :"
                . "\n\n#️⃣ _pour revenir en arrière_";
        }

        $user = $this->cotisationSvc->creerCompteLight(
            nom: $kyc['nom'],
            prenom: $kyc['prenom'],
            numeroE164: $numeroSaisi,
            projectId: $projectId,
        );
        $this->inscrireMembre($user, $cagnotte);

        $prenom = ucfirst(mb_strtolower($kyc['prenom']));
        $type   = $cagnotte->type === 'tontine_periodique' ? 'tontine' : 'cagnotte';

        return <<<TXT
        ✅ *Inscription confirmée !*

        Bienvenue *{$prenom}* ! Vous avez rejoint la {$type} *{$cagnotte->titre}* (N°{$ref}).

        TXT . "\n" . $this->afficherMenu($numero);
    }

    // ── 3 — Créer ─────────────────────────────────────────────────────────────

    /**
     * Démarre le flux de création d'une tontine ou cagnotte.
     * Positionne la session à 'creer.type' et propose le choix du type.
     *
     * @param  string $numero  Numéro E.164
     * @return string
     */
    private function demarrerCreer(string $numero): string
    {
        // « Cagnotte d'abord » : la tontine étant désactivée, il n'y a plus de
        // choix de type à poser. On saute l'étape 'creer.type' et on entre
        // directement dans le sous-flow cagnotte (même cible que le choix "1").
        // C'est le SEUL endroit où une tontine peut naître : la verrouiller ici
        // suffit à garantir qu'aucune tontine nouvelle n'est créée.
        if (! $this->tontinesActives()) {
            $this->session->set($numero, 'creer.cotisation.nom', [
                'project_id' => $this->tondoProjectId(),
                'type'       => 'cagnotte_ouverte',
            ]);

            return <<<TXT
            ✨ *Créer une cagnotte*

            Quel est le *nom* de votre cagnotte ?
            _(max 120 caractères)_

            #️⃣ _pour revenir en arrière_
            TXT;
        }

        $this->session->set($numero, 'creer.type', [
            'project_id' => $this->tondoProjectId(),
        ]);

        return <<<TXT
        ✨ *Créer une tontine ou une cagnotte*

        Que souhaitez-vous créer ?

        1️⃣  *Cagnotte* — collecte libre, montant variable
        2️⃣  *Tontine périodique* — rotation, montant fixe par cycle

        _Tapez le numéro de votre choix_ · #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Routeur interne pour toutes les sous-étapes du flow "Créer".
     * Toutes les étapes commençant par 'creer.' arrivent ici depuis traiter().
     *
     * Flow cotisation : creer.type → creer.cotisation.nom → creer.numero → creer.recap
     * Flow tontine    : creer.type → creer.tontine.nom → creer.tontine.nb_membres
     *                   → creer.tontine.montant_cycle → creer.tontine.periodicite
     *                   → [creer.tontine.jour_mois si mensuelle] → creer.numero
     *                   → [KYC Airtel auto + creer.otp si inconnu/light] → creer.recap
     *
     * @param  string $numero  Numéro E.164
     * @param  string $etape   Étape courante (ex : 'creer.tontine.nom')
     * @param  string $texte   Message reçu
     * @return string
     */
    private function routerCreer(string $numero, string $etape, string $texte): string
    {
        return match ($etape) {
            'creer.type'                       => $this->handleCreerType($numero, $texte),
            'creer.cotisation.nom'             => $this->handleCreerCotisationNom($numero, $texte),
            'creer.cotisation.montant_cible'   => $this->handleCreerCotisationMontantCible($numero, $texte),
            'creer.cotisation.date_fin'        => $this->handleCreerCotisationDateFin($numero, $texte),
            'creer.tontine.nom'             => $this->handleCreerTontineNom($numero, $texte),
            'creer.tontine.nb_membres' => $this->handleCreerTontineNbMembres($numero, $texte),
            'creer.tontine.montant_cycle'   => $this->handleCreerTontineMontantCycle($numero, $texte),
            'creer.tontine.periodicite'     => $this->handleCreerTontinePeriodicite($numero, $texte),
            'creer.tontine.jour_mois'       => $this->handleCreerTontineJourMois($numero, $texte),
            'creer.numero'                     => $this->handleCreerNumero($numero, $texte),
            'creer.otp'                        => $this->handleCreerOtp($numero, $texte),
            'creer.numero_retrait'             => $this->handleCreerNumeroRetrait($numero, $texte),
            'creer.recap'                      => $this->handleCreerRecap($numero, $texte),
            default                            => $this->afficherMenu($numero),
        };
    }

    /**
     * Traite le choix du type (1=cagnotte, 2=tontine) et oriente vers le bon sous-flow.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   "1" ou "2"
     * @return string
     */
    private function handleCreerType(string $numero, string $texte): string
    {
        $data = $this->session->data($numero);

        // Filet de sécurité : une session ouverte AVANT la désactivation des
        // tontines peut encore stationner à 'creer.type' (cache 30 min) et
        // taper "2". Si le flag est off, on ignore le choix et on bascule sur
        // le flux cagnotte, seul parcours autorisé.
        if (! $this->tontinesActives()) {
            return $this->demarrerCreer($numero);
        }

        if ($texte === '1') {
            $this->session->set($numero, 'creer.cotisation.nom', array_merge($data, [
                'type' => 'cagnotte_ouverte',
            ]));
            return <<<TXT
            💰 *Cagnotte*

            Quel est le *nom* de votre cagnotte ?
            _(max 120 caractères)_

            #️⃣ _pour revenir en arrière_
            TXT;
        }

        if ($texte === '2') {
            $this->session->set($numero, 'creer.tontine.nom', array_merge($data, [
                'type' => 'tontine_periodique',
            ]));
            return <<<TXT
            🔄 *Tontine périodique*

            Quel est le *nom* de votre tontine ?
            _(max 120 caractères)_

            #️⃣ _pour revenir en arrière_
            TXT;
        }

        return "⚠️ Tapez *1* pour Cagnotte ou *2* pour Tontine.\n\n#️⃣ _pour revenir en arrière_";
    }

    // ── 3.1 Cotisation — champs ───────────────────────────────────────────────

    /**
     * Collecte le nom de la cagnotte et saute directement à l'identification du créateur.
     * Dans le flow WhatsApp simplifié, montant_cible et date_fin ne sont pas demandés
     * (ils sont initialisés à 0/null). Seul le nom est collecté.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Nom de la cagnotte (3-120 caractères)
     * @return string
     */
    private function handleCreerCotisationNom(string $numero, string $texte): string
    {
        $titre = trim($texte);
        if (mb_strlen($titre) < 3 || mb_strlen($titre) > 120) {
            return "⚠️ Nom invalide (3 à 120 caractères).\n\n#️⃣ _pour revenir en arrière_";
        }

        $data = $this->session->data($numero);
        // montant_cible = 0 et date_fin = null : pas de limite par défaut (WhatsApp flow simplifié)
        $this->session->set($numero, 'creer.numero', array_merge($data, [
            'titre'         => $titre,
            'montant_cible' => 0,
            'date_fin'      => null,
        ]));

        return $this->demanderNumeroCreateur();
    }

    /**
     * Collecte le montant cible de la cagnotte (optionnel, 0 = pas de limite).
     * Note : cette étape n'est pas atteinte dans le flow WhatsApp simplifié actuel
     * (handleCreerCotisationNom saute directement à creer.numero). Elle reste
     * disponible pour une version future plus complète.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Montant saisi (0 = pas de limite)
     * @return string
     */
    private function handleCreerCotisationMontantCible(string $numero, string $texte): string
    {
        $montant = (int) preg_replace('/\D/', '', $texte);

        if ($montant !== 0 && ($montant < 100 || $montant > 2_500_000)) {
            return "⚠️ Montant invalide. Entre *100* et *2 500 000 FCFA*, ou *0* pour pas de limite.\n\n#️⃣ _pour revenir en arrière_";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'creer.cotisation.date_fin', array_merge($data, [
            'montant_cible' => $montant,
        ]));

        return <<<TXT
        Date *limite* de la cagnotte ?
        _(format : *JJ/MM/AAAA* — tapez *0* pour pas de date limite)_

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Collecte la date de fin de la cagnotte (format JJ/MM/AAAA ou "0" pour sans limite).
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Date saisie ou "0"
     * @return string
     */
    private function handleCreerCotisationDateFin(string $numero, string $texte): string
    {
        $data = $this->session->data($numero);

        if (trim($texte) === '0') {
            $this->session->set($numero, 'creer.numero', array_merge($data, ['date_fin' => null]));
            return $this->demanderNumeroCreateur();
        }

        $dt = $this->parseDate(trim($texte));
        if (! $dt) {
            return "⚠️ Format invalide. Utilisez *JJ/MM/AAAA* ou tapez *0* pour aucune limite.\n\n#️⃣ _pour revenir en arrière_";
        }
        if ($dt <= new \DateTimeImmutable('today')) {
            return "⚠️ La date limite doit être *après aujourd'hui*.\n\n#️⃣ _pour revenir en arrière_";
        }

        $this->session->set($numero, 'creer.numero', array_merge($data, [
            'date_fin' => $dt->format('Y-m-d'),
        ]));
        return $this->demanderNumeroCreateur();
    }

    // ── 3.1 Tontine — champs ─────────────────────────────────────────────────

    /**
     * Collecte le nom de la tontine.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Nom saisi (3-120 caractères)
     * @return string
     */
    private function handleCreerTontineNom(string $numero, string $texte): string
    {
        $titre = trim($texte);
        if (mb_strlen($titre) < 3 || mb_strlen($titre) > 120) {
            return "⚠️ Nom invalide (3 à 120 caractères).\n\n#️⃣ _pour revenir en arrière_";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'creer.tontine.nb_membres', array_merge($data, [
            'titre' => $titre,
        ]));

        return <<<TXT
        Nombre de *membres* ?
        _(entre 2 et 200)_

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Collecte le nombre de membres de la tontine (2-200).
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Nombre saisi
     * @return string
     */
    private function handleCreerTontineNbMembres(string $numero, string $texte): string
    {
        $nb = (int) preg_replace('/\D/', '', $texte);
        if ($nb < 2 || $nb > 200) {
            return "⚠️ Nombre invalide. Entre *2* et *200* membres.\n\n#️⃣ _pour revenir en arrière_";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'creer.tontine.montant_cycle', array_merge($data, [
            'nombre_participants' => $nb,
        ]));

        return <<<TXT
        Montant *récupéré par membre* ? _(sans les frais)_
        (en FCFA)

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Collecte le montant que chaque bénéficiaire recevra à son tour (cashBack).
     * C'est le montant NET — les frais seront calculés par AirtelFeesCalculator.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Montant saisi (100-2 500 000 FCFA)
     * @return string
     */
    private function handleCreerTontineMontantCycle(string $numero, string $texte): string
    {
        $montant = (int) preg_replace('/\D/', '', $texte);
        if ($montant < 100 || $montant > 2_500_000) {
            return "⚠️ Montant invalide. Entre *100* et *2 500 000 FCFA*.\n\n#️⃣ _pour revenir en arrière_";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'creer.tontine.periodicite', array_merge($data, [
            'montant_par_cycle' => $montant,
        ]));

        return <<<TXT
        *Fréquence* de reversement ?

        1️⃣  *1 semaine* (retrait le lundi)
        2️⃣  *2 semaines* (retrait le lundi)
        3️⃣  *1 mois* (vous choisirez le jour)

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Collecte la périodicité de la tontine (1=hebdo, 2=bi-hebdo, 3=mensuelle).
     * Pour les options 1 et 2 (hebdomadaire), le jour est fixé à lundi.
     * Pour l'option 3 (mensuelle), demande le jour du mois (étape suivante).
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   "1", "2" ou "3"
     * @return string
     */
    private function handleCreerTontinePeriodicite(string $numero, string $texte): string
    {
        if (! in_array($texte, ['1', '2', '3'])) {
            return "⚠️ Tapez *1*, *2* ou *3*.\n\n#️⃣ _pour revenir en arrière_";
        }

        $data    = $this->session->data($numero);
        $mapping = [
            '1' => ['periodicite' => 'hebdomadaire', 'intervalle' => 1, 'jour' => 'lundi'],
            '2' => ['periodicite' => 'hebdomadaire', 'intervalle' => 2, 'jour' => 'lundi'],
        ];

        if ($texte === '3') {
            $this->session->set($numero, 'creer.tontine.jour_mois', array_merge($data, [
                'periodicite' => 'mensuelle', 'intervalle' => 1, 'penalite_active' => false,
            ]));
            return <<<TXT
            Quel *jour du mois* pour le versement ?

            1️⃣  Le *5*
            2️⃣  Le *7*
            3️⃣  Le *15*

            #️⃣ _pour revenir en arrière_
            TXT;
        }

        $this->session->set($numero, 'creer.numero', array_merge($data, $mapping[$texte], [
            'penalite_active' => false,
        ]));

        return $this->demanderNumeroCreateur();
    }

    /**
     * Collecte le jour du mois pour une tontine mensuelle.
     * Trois choix : 1 → le 5, 2 → le 7, 3 → le 15.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   "1", "2" ou "3"
     * @return string
     */
    private function handleCreerTontineJourMois(string $numero, string $texte): string
    {
        if (! in_array($texte, ['1', '2', '3'])) {
            return "⚠️ Tapez *1* (le 5), *2* (le 7) ou *3* (le 15).\n\n#️⃣ _pour revenir en arrière_";
        }

        $jourMap = ['1' => 5, '2' => 7, '3' => 15];
        $data    = $this->session->data($numero);

        $this->session->set($numero, 'creer.numero', array_merge($data, [
            'jour' => $jourMap[$texte],
        ]));

        return $this->demanderNumeroCreateur();
    }

    // ── 3.2 Identification du créateur (partagé cotisation + tontine) ─────────

    /**
     * Retourne le message demandant le numéro Mobile Money du créateur.
     * Partagé entre le flow cotisation et le flow tontine.
     *
     * @return string
     */
    private function demanderNumeroCreateur(): string
    {
        return <<<TXT
        📱 Votre *numéro Mobile Money* ?
        _(format : *0XXXXXXXX*)_

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Traite la saisie du numéro Mobile Money du créateur.
     *
     * Cas possibles :
     *   - Utilisateur connu avec profil complet → afficher le récapitulatif directement.
     *   - Utilisateur connu avec profil vide (compte light anonyme) → demander nom/prénom.
     *   - Utilisateur inconnu → demander nom/prénom (création d'un compte full).
     *
     * @param  string $numero  Numéro E.164 (expéditeur WhatsApp)
     * @param  string $texte   Numéro Mobile Money saisi
     * @return string
     */
    private function handleCreerNumero(string $numero, string $texte): string
    {
        $numeroSaisi = $this->normaliserNumero($texte);
        if (! $numeroSaisi) {
            return "⚠️ Numéro invalide. Format : *0XXXXXXXX*\n\n#️⃣ _pour revenir en arrière_";
        }

        $data      = $this->session->data($numero);
        $projectId = $data['project_id'] ?? $this->tondoProjectId();
        $user      = $this->utilisateurParNumero($numeroSaisi, $projectId);

        // Compte déjà complet (nom + prénom renseignés) → on va direct au récap,
        // pas besoin de KYC ni d'OTP : l'utilisateur est déjà connu.
        if ($user && trim($user->nom) !== '' && trim($user->prenom) !== '') {
            $merged = array_merge($data, [
                'user_id'        => $user->id,
                'numero_payeur'  => $numeroSaisi,
                'numero_retrait' => $numeroSaisi,
            ]);
            $this->session->set($numero, 'creer.recap', $merged);
            return $this->construireRecap($merged, $numeroSaisi);
        }

        // Sinon (inconnu OU compte light sans identité) : on NE demande plus le
        // nom/prénom. On les récupère via le KYC Airtel Money (même logique que
        // l'app mobile), puis on enchaîne directement sur l'OTP.
        return $this->demarrerKycPuisOtp($numero, $numeroSaisi, $data, $user?->id);
    }

    /**
     * Vérifie le numéro via le KYC Airtel Money (comme l'app mobile) puis enchaîne
     * sur l'OTP — sans jamais demander le nom/prénom.
     *
     * - KYC OK : message « Bienvenue {prénom} {nom} » + envoi de l'OTP + passage
     *            à l'étape creer.otp (le compte est créé après validation du code).
     * - KYC KO : message d'erreur, on redemande un numéro Airtel Money (on reste
     *            à l'étape creer.numero).
     *
     * @param  string      $numero       Numéro E.164 WhatsApp (clé de session)
     * @param  string      $numeroSaisi  Numéro Mobile Money saisi (E.164)
     * @param  array       $data         Données de session courantes
     * @param  string|null $userId       Id du compte light éventuel à promouvoir
     * @return string
     */
    private function demarrerKycPuisOtp(string $numero, string $numeroSaisi, array $data, ?string $userId): string
    {
        $kyc = $this->verifierKycAirtel($numeroSaisi);

        // Numéro non éligible (pas Airtel, sans compte, service indispo) → on redemande.
        if ($kyc['bloque']) {
            return $kyc['message']
                . "\n\nEntrez un *numéro Airtel Money* (format *0XXXXXXXX*) :"
                . "\n\n#️⃣ _pour revenir en arrière_";
        }

        // KYC OK → identité récupérée automatiquement. On envoie l'OTP tout de suite.
        [$otp, $hint] = $this->envoyerOtp($numeroSaisi);

        $merged = array_merge($data, [
            'numero_payeur' => $numeroSaisi,
            'nom'           => $kyc['nom'],
            'prenom'        => $kyc['prenom'],
            'otp'           => $otp,
        ]);
        if ($userId !== null) {
            $merged['user_id'] = $userId;   // compte light à promouvoir en full
        }
        $this->session->set($numero, 'creer.otp', $merged);

        $masque = $this->maskPhoneNum($numeroSaisi);
        $prenom = ucfirst(mb_strtolower(trim($kyc['prenom'])));
        $nom    = mb_strtoupper(trim($kyc['nom']));

        return <<<TXT
        🎉 *Bienvenue {$prenom} {$nom} !*

        Votre compte Airtel Money est vérifié.{$hint}

        🔐 Un code à 6 chiffres vient de vous être envoyé par SMS au *{$masque}*.
        Entrez ce code pour créer votre compte et finaliser la cagnotte.

        _En validant, vous certifiez avoir 18 ans ou plus et acceptez les conditions Tonji._

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Reproduit la logique KYC du mobile (AuthController::kycCheck) pour le bot :
     * détection opérateur + config active + appel KYC Airtel Money.
     *
     * @param  string $numeroE164  Numéro Mobile Money au format E.164 (+241XXXXXXXX)
     * @return array{bloque: bool, message?: string, nom?: string, prenom?: string, type_client?: string}
     */
    private function verifierKycAirtel(string $numeroE164): array
    {
        $projectId = $this->tondoProjectId();
        // msisdn local 0XXXXXXXX à partir de l'E.164 (+241 + 8 chiffres abonné).
        $msisdn = '0' . substr(preg_replace('/\D/', '', $numeroE164), -8);

        // 1. Détection de l'opérateur — seul Airtel a une API KYC.
        $detected = app(\App\Services\OperateurDetectorService::class)->detect($projectId, $numeroE164);
        if (! $detected || ($detected['operateur'] ?? null) !== 'airtel') {
            return ['bloque' => true, 'message' => "⚠️ Ce numéro n'est pas un compte *Airtel Money*."];
        }

        // 2. Opérateur activé dans la config projet ?
        $configActif = \App\Models\TondoProjectConfig::where('project_id', $projectId)
            ->where('operateur', 'airtel')
            ->where('pays', $detected['pays'])
            ->value('actif');
        if (! $configActif) {
            return ['bloque' => true, 'message' => "⚠️ Ce numéro n'est pas un compte *Airtel Money*."];
        }

        // 3. Appel KYC Airtel.
        $kyc = app(\App\Services\PaynalaPaymentService::class)->checkKycData($msisdn);
        if ($kyc === null) {
            return ['bloque' => true, 'message' => "⏳ La vérification Airtel Money est temporairement indisponible. Réessayez dans quelques minutes."];
        }
        if (! ($kyc['ok'] ?? false)) {
            return ['bloque' => true, 'message' => "❌ Ce numéro n'a pas de compte *Airtel Money* actif. Vérifiez votre numéro."];
        }

        // 4. KYC réussi → identité récupérée pour auto-complétion.
        return [
            'bloque'      => false,
            'nom'         => $kyc['nom']         ?? '',
            'prenom'      => $kyc['prenom']      ?? '',
            'type_client' => $kyc['type_client'] ?? 'particulier',
        ];
    }

    /**
     * Vérifie l'OTP puis crée le compte du nouveau gérant (flux « creer »).
     *
     * Étape insérée pour les clients non encore enregistrés (ou comptes light)
     * qui créent une cagnotte/tontine : le compte n'est créé qu'après preuve de
     * possession du numéro, comme dans le flux « gérer ».
     * Sur succès : creerCompteFull (date de naissance placeholder, non collectée
     * via WhatsApp) puis affichage du récapitulatif.
     *
     * @param  string $numero  Numéro E.164 WhatsApp (clé de session)
     * @param  string $texte   Code OTP à 6 chiffres saisi par l'utilisateur
     * @return string
     */
    private function handleCreerOtp(string $numero, string $texte): string
    {
        $data = $this->session->data($numero);
        $code = trim($texte);

        // Format attendu : exactement 6 chiffres.
        if (! preg_match('/^\d{6}$/', $code)) {
            return "⚠️ Entrez le code à *6 chiffres*.\n\n#️⃣ _pour revenir en arrière_";
        }

        // Vérification réelle via OtpService (driver paynala → cache/Wirepick).
        if (! $this->verifierOtp($data['numero_payeur'] ?? '', $code, $data['otp'] ?? '')) {
            return "❌ Code incorrect ou expiré.\nRessayez ou #️⃣ pour revenir en arrière.";
        }

        // OTP validé — création du compte full puis récapitulatif de la cagnotte.
        $projectId = $data['project_id'] ?? $this->tondoProjectId();
        $user = $this->cotisationSvc->creerCompteFull(
            nom:           $data['nom'],
            prenom:        $data['prenom'],
            numeroE164:    $data['numero_payeur'],
            projectId:     $projectId,
            dateNaissance: '2000-01-01',
        );

        $numeroRetrait = $data['numero_payeur'];
        $merged        = array_merge($data, [
            'user_id'        => $user->id,
            'numero_retrait' => $numeroRetrait,
        ]);
        $this->session->set($numero, 'creer.recap', $merged);

        return $this->construireRecap($merged, $numeroRetrait);
    }

    /**
     * Retourne le message demandant un numéro de retrait alternatif.
     * Proposé quand l'utilisateur veut reverser sur un numéro différent.
     *
     * @param  string $prenom  Prénom de l'utilisateur pour la salutation
     * @return string
     */
    private function demanderNumeroRetrait(string $prenom): string
    {
        $salut = $prenom ? "Bonjour *{$prenom}* !" : 'Bonjour !';
        return <<<TXT
        {$salut}

        Le montant collecté sera reversé sur votre numéro Mobile Money.
        Voulez-vous utiliser un *autre numéro* pour le retrait ?

        _(tapez le numéro alternatif au format *0XXXXXXXX*, ou *0* pour utiliser le même)_

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Collecte le numéro de retrait alternatif (si l'utilisateur veut reverser ailleurs).
     * "0" = utiliser le même numéro que celui saisi pour l'identification.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Numéro alternatif ou "0"
     * @return string
     */
    private function handleCreerNumeroRetrait(string $numero, string $texte): string
    {
        $data = $this->session->data($numero);

        if (trim($texte) === '0') {
            $numeroRetrait = $data['numero_payeur'];
        } else {
            $numeroRetrait = $this->normaliserNumero($texte);
            if (! $numeroRetrait) {
                return "⚠️ Numéro invalide. Format : *0XXXXXXXX* ou tapez *0* pour le même numéro.\n\n#️⃣ _pour revenir en arrière_";
            }
        }

        $this->session->set($numero, 'creer.recap', array_merge($data, [
            'numero_retrait' => $numeroRetrait,
        ]));

        return $this->construireRecap($data, $numeroRetrait);
    }

    // ── 3.3 Récap + CGU ──────────────────────────────────────────────────────

    /**
     * Construit le message de récapitulatif avant confirmation.
     * Le contenu diffère selon le type (tontine vs cagnotte).
     * Suivi du texte CGU simplifié avec lien et instructions de validation.
     *
     * @param  array  $data           Données de session (titre, type, membres…)
     * @param  string $numeroRetrait  Numéro sur lequel sera versé le montant collecté
     * @return string
     */
    private function construireRecap(array $data, string $numeroRetrait): string
    {
        $masque = $this->maskPhoneNum($numeroRetrait);

        if ($data['type'] === 'tontine_periodique') {
            $montant    = number_format((int) $data['montant_par_cycle'], 0, ',', ' ');
            $intervalle = (int) ($data['intervalle'] ?? 1);
            $jour       = $data['jour'] ?? '?';
            $jourStr    = is_string($jour) ? ucfirst($jour) : 'le ' . $jour . ' du mois';

            if ($data['periodicite'] === 'hebdomadaire') {
                $freq = $intervalle === 1 ? 'Toutes les semaines' : "Toutes les {$intervalle} semaines";
                $freq .= " ({$jourStr})";
            } else {
                $freq = "1 fois/mois ({$jourStr})";
            }

            $lignes = <<<TXT
            📝 *Récapitulatif — Tontine périodique*

            Nom : *{$data['titre']}*
            Membres : *{$data['nombre_participants']}*
            Montant/cycle : *{$montant} FCFA*
            Fréquence : *{$freq}*
            Numéro de retrait : *{$masque}*
            TXT;
        } else {
            $cible    = isset($data['montant_cible']) && (int) $data['montant_cible'] > 0
                ? number_format((int) $data['montant_cible'], 0, ',', ' ') . ' FCFA'
                : 'Pas de limite';
            $dateFin  = $data['date_fin'] ?? null;
            $dateFin  = $dateFin ? (new \DateTimeImmutable($dateFin))->format('d/m/Y') : 'Pas de limite';

            $lignes = <<<TXT
            📝 *Récapitulatif — Cagnotte*

            Nom : *{$data['titre']}*
            Montant cible : *{$cible}*
            Date limite : *{$dateFin}*
            Numéro de retrait : *{$masque}*
            TXT;
        }

        return $lignes . "\n\n" . $this->cguTexte();
    }

    /**
     * Retourne le texte court des CGU avec lien et instructions de validation.
     * Conforme à RÈGLE 4-bis : résumé direct + lien vers version complète.
     *
     * @return string
     */
    private function cguTexte(): string
    {
        return <<<TXT
        En confirmant, vous acceptez les conditions d'utilisation Tonji : https://tonji.ga/cgu

        Tapez *1* pour confirmer et créer · *0* pour annuler.
        TXT;
    }

    // ── 3.4 Création effective ────────────────────────────────────────────────

    /**
     * Traite la confirmation du récapitulatif et crée la cagnotte/tontine.
     *
     * Sur "1" : appelle CreerCagnotteService::creer() puis retourne le message
     * de succès avec le deep link WhatsApp à partager aux membres.
     * Le deep link est construit avec le numéro du bot (config tondo.whatsapp_numero).
     *
     * Sur "0" : annulation, retour menu.
     * Autre valeur : réaffiche le récapitulatif avec rappel de confirmation.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   "1" (confirmer), "0" (annuler), autre (rappel)
     * @return string
     */
    private function handleCreerRecap(string $numero, string $texte): string
    {
        if ($texte === '0') {
            return $this->erreurEtMenu($numero, "🚫 Création annulée.");
        }

        if ($texte !== '1') {
            // Toute autre saisie → réafficher le récap avec invitation à confirmer
            $data = $this->session->data($numero);
            return $this->construireRecap($data, $data['numero_retrait'] ?? '') .
                "\n\n⚠️ Tapez *1* pour confirmer ou *0* pour annuler.";
        }

        $data = $this->session->data($numero);
        $user = TondoUser::find($data['user_id'] ?? null);

        if (! $user) {
            return $this->erreurEtMenu($numero, "❌ Session expirée. Recommencez.");
        }

        try {
            $cagnotte = $this->creerCagnotteSvc->creer($data, $user);
        } catch (\Throwable $e) {
            Log::error('handleCreerRecap: échec création', ['err' => $e->getMessage()]);
            return $this->erreurEtMenu($numero, "❌ Erreur lors de la création. Réessayez ou contactez support@tonji.ga.");
        }

        $type = $cagnotte->type === 'tontine_periodique' ? 'tontine' : 'cagnotte';
        // Lexique aligné sur le mobile et le web : on parle de « code de la
        // cagnotte » (et non de « numéro »), la tontine gardant son libellé.
        $refLabel = $cagnotte->type === 'tontine_periodique'
            ? 'Numéro de tontine'
            : 'Code de la cagnotte';
        $prenom = ucfirst(mb_strtolower($user->prenom));
        $ref    = $cagnotte->reference;
        // Construire le deep link WhatsApp pour que les membres rejoignent directement
        $botNum = ltrim(config('tondo.whatsapp_numero', ''), '+');
        $lienWa = $botNum
            ? "\nhttps://wa.me/{$botNum}?text=" . rawurlencode("TONJI {$ref}")
            : " N°*{$ref}*";

        return <<<TXT
        🎉 *{$cagnotte->titre}* créée avec succès !

        Félicitations *{$prenom}* !
        Votre {$type} est active.

        *{$refLabel} : N°{$ref}*
        Partagez ce lien à vos membres :{$lienWa}

        TXT . "\n" . $this->afficherMenu($numero);
    }

    // ── 3 — Helpers ──────────────────────────────────────────────────────────

    /**
     * Parse une date au format JJ/MM/AAAA ou JJ-MM-AAAA.
     * Valide que le jour et le mois sont cohérents (rejet de 31/02 etc.).
     *
     * @param  string $texte  Date saisie par l'utilisateur
     * @return \DateTimeImmutable|null  null si format invalide ou date incohérente
     */
    private function parseDate(string $texte): ?\DateTimeImmutable
    {
        if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $texte, $m)) {
            try {
                $dt = new \DateTimeImmutable("{$m[3]}-{$m[2]}-{$m[1]}");
                // Vérifier que la date est valide (ex: 31/02 rejeté)
                if ($dt->format('d') === $m[1] && $dt->format('m') === $m[2]) {
                    return $dt;
                }
            } catch (\Exception) {}
        }
        return null;
    }

    /**
     * Vérifie qu'une date de naissance correspond à un utilisateur majeur (≥ 18 ans).
     *
     * @param  \DateTimeImmutable $naissance  Date de naissance
     * @return bool
     */
    private function estMajeur(\DateTimeImmutable $naissance): bool
    {
        return $naissance->diff(new \DateTimeImmutable('today'))->y >= 18;
    }

    // ── 4 — Gérer ─────────────────────────────────────────────────────────────

    /**
     * Démarre le flow de gestion des cagnottes/tontines.
     * Exige une vérification OTP avant d'afficher la liste (sécurité).
     *
     * @param  string $numero  Numéro E.164
     * @return string
     */
    private function demarrerGerer(string $numero): string
    {
        $this->session->set($numero, 'gerer.numero', [
            'project_id' => $this->tondoProjectId(),
        ]);

        return <<<TXT
        📋 *Gérer mes cagnottes*

        Votre *numéro Mobile Money* ?
        _(format : *0XXXXXXXX*)_

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Routeur interne pour toutes les sous-étapes du flow "Gérer".
     * Toutes les étapes commençant par 'gerer.' arrivent ici depuis traiter().
     *
     * Flow principal : gerer.numero → gerer.otp → gerer.liste → gerer.cagnotte | gerer.tontine
     * Flow reversement : gerer.revers.dest → [gerer.revers.num] → gerer.revers.mont → gerer.revers.otp
     * Flow fermer : gerer.fermer.confirm → [gerer.fermer.num] → gerer.fermer.otp
     *
     * @param  string $numero  Numéro E.164
     * @param  string $etape   Étape courante (ex : 'gerer.revers.mont')
     * @param  string $texte   Message reçu
     * @return string
     */
    private function routerGerer(string $numero, string $etape, string $texte): string
    {
        return match ($etape) {
            'gerer.numero'           => $this->handleGererNumero($numero, $texte),
            'gerer.otp'              => $this->handleGererOtp($numero, $texte),
            'gerer.nom_prenom'       => $this->handleGererNomPrenom($numero, $texte),
            'gerer.certification'    => $this->handleGererCertification($numero, $texte),
            'gerer.liste'            => $this->handleGererListe($numero, $texte),
            'gerer.cagnotte'         => $this->handleGererCagnotte($numero, $texte),
            'gerer.historique'       => $this->handleGererHistorique($numero, $texte),
            'gerer.revers.dest'      => $this->handleGererReversementDest($numero, $texte),
            'gerer.revers.num'       => $this->handleGererReversementNum($numero, $texte),
            'gerer.revers.mont'      => $this->handleGererReversementMontant($numero, $texte),
            'gerer.revers.otp'       => $this->handleGererReversementOtp($numero, $texte),
            'gerer.fermer.confirm'   => $this->handleGererFermerConfirm($numero, $texte),
            'gerer.fermer.num'       => $this->handleGererFermerNum($numero, $texte),
            'gerer.fermer.otp'       => $this->handleGererFermerOtp($numero, $texte),
            'gerer.tontine'          => $this->handleGererTontine($numero, $texte),
            'gerer.tontine.ordre'    => $this->handleGererTontineOrdre($numero, $texte),
            'gerer.tontine.hist'     => $this->handleGererTontineHistorique($numero, $texte),
            default                  => $this->afficherMenu($numero),
        };
    }

    /**
     * Traite la saisie du numéro Mobile Money pour le flow Gérer.
     * Envoie un OTP et passe à l'étape 'gerer.otp'.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Numéro Mobile Money saisi
     * @return string
     */
    private function handleGererNumero(string $numero, string $texte): string
    {
        $numeroSaisi = $this->normaliserNumero($texte);
        if (! $numeroSaisi) {
            return "⚠️ Numéro invalide. Format : *0XXXXXXXX*\n\n#️⃣ _pour revenir en arrière_";
        }

        $data = $this->session->data($numero);
        [$otp, $hint] = $this->envoyerOtp($numeroSaisi);

        $this->session->set($numero, 'gerer.otp', array_merge($data, [
            'numero_payeur' => $numeroSaisi,
            'otp'           => $otp,
        ]));

        $masque = $this->maskPhoneNum($numeroSaisi);

        return <<<TXT
        🔐 *Vérification de votre identité*

        Un code à 6 chiffres a été envoyé au *{$masque}*.{$hint}
        Entrez ce code pour continuer :

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Vérifie le code OTP saisi pour le flow Gérer.
     * Sur succès : affiche la liste des cagnottes gérées.
     * Si l'utilisateur est inconnu (nouveau) : demande nom + prénom.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Code OTP à 6 chiffres
     * @return string
     */
    private function handleGererOtp(string $numero, string $texte): string
    {
        $data = $this->session->data($numero);
        $code = trim($texte);

        if (! preg_match('/^\d{6}$/', $code)) {
            return "⚠️ Entrez le code à *6 chiffres*.\n\n#️⃣ _pour revenir en arrière_";
        }

        if (! $this->verifierOtp($data['numero_payeur'] ?? '', $code, $data['otp'] ?? '')) {
            return "❌ Code incorrect ou expiré.\nRessayez ou #️⃣ pour revenir en arrière.";
        }

        // OTP validé — poursuivre avec la liste des cagnottes
        $numeroSaisi = $data['numero_payeur'] ?? '';
        $projectId   = $data['project_id'] ?? $this->tondoProjectId();
        $user        = $this->utilisateurParNumero($numeroSaisi, $projectId);

        if ($user) {
            return $this->afficherListeCagnottes($numero, $user, array_merge($data, [
                'user_id' => $user->id,
            ]));
        }

        $this->session->set($numero, 'gerer.nom_prenom', $data);

        return <<<TXT
        ✅ *Identité vérifiée !*

        Vous n'avez pas encore de compte Tonji. On va en créer un.

        Entrez votre *nom* puis votre *prénom*, chacun sur une ligne :

        _Exemple :_
        MBOULA
        Jean

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Collecte nom + prénom pour un nouveau gérant (inconnu à l'OTP).
     * Passe à la certification de majorité.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Nom et prénom (deux lignes)
     * @return string
     */
    private function handleGererNomPrenom(string $numero, string $texte): string
    {
        $lignes = array_values(array_filter(array_map('trim', explode("\n", $texte))));

        if (count($lignes) < 2) {
            return "⚠️ Format incorrect. Entrez *nom* puis *prénom*, chacun sur une ligne.\n\n#️⃣ _pour revenir en arrière_";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'gerer.certification', array_merge($data, [
            'nom'    => mb_strtoupper(trim($lignes[0])),
            'prenom' => ucfirst(mb_strtolower(trim($lignes[1]))),
        ]));

        return $this->messageCertification();
    }

    /**
     * Certification de majorité dans le flow Gérer (même logique que dans Créer).
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   "1" pour certifier
     * @return string
     */
    private function handleGererCertification(string $numero, string $texte): string
    {
        if ($texte !== '1') {
            return "⚠️ Tapez *1* pour certifier votre majorité, #️⃣ pour revenir en arrière.";
        }

        $data      = $this->session->data($numero);
        $projectId = $data['project_id'] ?? $this->tondoProjectId();

        $user = $this->cotisationSvc->creerCompteFull(
            nom:           $data['nom'],
            prenom:        $data['prenom'],
            numeroE164:    $data['numero_payeur'],
            projectId:     $projectId,
            dateNaissance: '2000-01-01',
        );

        return $this->afficherListeCagnottes($numero, $user, array_merge($data, [
            'user_id' => $user->id,
        ]));
    }

    /**
     * Retourne le message de certification de majorité (partagé creer + gerer).
     *
     * @return string
     */
    private function messageCertification(): string
    {
        return <<<TXT
        🔞 *Confirmation de majorité*

        Pour utiliser Tonji, vous devez avoir *18 ans ou plus*.

        Tapez *1* pour certifier être majeur et accepter les conditions d'utilisation : https://tonji.ga/cgu

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Affiche la liste numérotée des cagnottes/tontines gérées par l'utilisateur.
     *
     * Stocke le tableau des références dans la session (clé 'refs') pour permettre
     * une sélection soit par numéro de position (1, 2…) soit par référence directe.
     *
     * @param  string    $numero  Numéro E.164
     * @param  TondoUser $user    Utilisateur authentifié (gérant)
     * @param  array     $data    Données de session courantes
     * @return string
     */
    private function afficherListeCagnottes(string $numero, TondoUser $user, array $data): string
    {
        $cagnottes = $this->gererCagnotteSvc->cagnottesGerees($user);

        if ($cagnottes->isEmpty()) {
            return $this->erreurEtMenu($numero,
                "📭 Vous n'avez aucune cagnotte active.\nTapez *3* pour en créer une."
            );
        }

        $liste    = $cagnottes->values();
        // Le suffixe de type n'est affiché que pour les tontines héritées : en
        // « Cagnotte d'abord », une ligne sans suffixe est une cagnotte.
        $index    = $liste->map(fn ($c, $i) => ($i + 1) . ". *{$c->titre}* · N°{$c->reference} · "
            . ($c->type === 'tontine_periodique' ? 'Tontine' : 'Cagnotte')
        )->implode("\n");

        $refs = $liste->pluck('reference')->toArray();

        $this->session->set($numero, 'gerer.liste', array_merge($data, ['refs' => $refs]));

        return <<<TXT
        📋 *Vos cagnottes actives*

        {$index}

        Quelle cagnotte souhaitez-vous gérer ?
        _(tapez le numéro de la liste, ou entrez directement le code de la cagnotte)_

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Traite la sélection d'une cagnotte depuis la liste.
     *
     * Accepte deux formes de saisie :
     *   - Position dans la liste (ex : "2" pour la 2e cagnotte)
     *   - Référence directe (ex : "315167")
     * La référence directe est prioritaire sur la position.
     * Redirige ensuite vers le menu cagnotte ou tontine selon le type.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Saisie utilisateur (position ou référence)
     * @return string
     */
    private function handleGererListe(string $numero, string $texte): string
    {
        $data  = $this->session->data($numero);
        $refs  = $data['refs'] ?? [];
        $n     = count($refs);
        $input = trim($texte);
        $choix = (int) $input;

        // Référence saisie directement → priorité sur la sélection positionnelle
        if (in_array($input, $refs, true)) {
            $ref = $input;
        } elseif ($choix >= 1 && $choix <= $n) {
            $ref = $refs[$choix - 1];
        } else {
            return "⚠️ Tapez un chiffre entre *1* et *{$n}*, ou entrez directement le *code de la cagnotte*.\n\n#️⃣ _pour revenir en arrière_";
        }

        $cagnotte = TondoCagnotte::where('reference', $ref)->first();

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Cagnotte introuvable. Recommencez.");
        }

        $newData = array_merge($data, [
            'cagnotte_id'  => $cagnotte->id,
            'cagnotte_ref' => $ref,
        ]);

        if ($cagnotte->type === 'tontine_periodique') {
            return $this->afficherMenuTontine($numero, $cagnotte, $newData);
        }

        $collecte = number_format((int) $cagnotte->montant_collecte, 0, ',', ' ');

        $this->session->set($numero, 'gerer.cagnotte', $newData);

        return <<<TXT
        💼 *{$cagnotte->titre}* · N°{$ref}
        Solde disponible : *{$collecte} FCFA*

        Que souhaitez-vous faire ?

        1️⃣  *Historique* des transactions
        2️⃣  *Initier* un reversement
        3️⃣  *Fermer* la cagnotte
        4️⃣  Retour à la liste

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Menu principal d'une cagnotte sélectionnée (historique, reversement, fermeture).
     * Options : 1=historique, 2=reversement, 3=fermer, 4=retour liste.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Choix (1-4)
     * @return string
     */
    private function handleGererCagnotte(string $numero, string $texte): string
    {
        $data     = $this->session->data($numero);
        $cagnotte = TondoCagnotte::find($data['cagnotte_id'] ?? null);

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Session expirée. Recommencez.");
        }

        if ($texte === '4') {
            return $this->retourListeCagnottes($numero, $data);
        }

        if ($texte === '3') {
            $solde = (int) $cagnotte->montant_collecte;

            if ($solde === 0) {
                $this->session->set($numero, 'gerer.fermer.confirm', array_merge($data, [
                    'fermer_solde_zero' => true,
                ]));

                return <<<TXT
                ⚠️ *Fermer la cagnotte ?*

                La cagnotte *{$cagnotte->titre}* sera clôturée définitivement.
                Solde : *0 FCFA* — aucun reversement nécessaire.

                1️⃣  Oui, fermer définitivement
                2️⃣  Non, annuler

                _Cette action est irréversible._
                TXT;
            }

            $soldeFmt   = number_format($solde, 0, ',', ' ');
            $numRetrait = $cagnotte->numero_retrait ?? ($data['numero_payeur'] ?? '');
            $masque     = $this->maskPhoneNum($numRetrait);

            $this->session->set($numero, 'gerer.fermer.confirm', array_merge($data, [
                'fermer_solde_zero' => false,
            ]));

            return <<<TXT
            🔒 *Fermer la cagnotte*

            Il reste *{$soldeFmt} FCFA* à reverser.
            Numéro de retrait enregistré : *{$masque}*

            1️⃣  Reverser vers ce numéro et fermer
            2️⃣  Changer le numéro de destination
            3️⃣  Annuler

            _La cagnotte sera clôturée après confirmation du reversement._
            TXT;
        }

        if ($texte === '1') {
            $paiements = $this->gererCagnotteSvc->historiquePaiements($cagnotte);

            if ($paiements->isEmpty()) {
                $this->session->set($numero, 'gerer.cagnotte', $data);
                return <<<TXT
                📊 *Historique — {$cagnotte->titre}*

                Aucune transaction confirmée pour le moment.

                1️⃣  *Historique* des transactions
                2️⃣  *Initier* un reversement
                3️⃣  *Fermer* la cagnotte
                4️⃣  Retour à la liste
                TXT;
            }

            $nbTotal = $paiements->count();
            $total   = number_format((int) $paiements->sum('montant'), 0, ',', ' ');
            $cinq    = $paiements->take(5);
            $lignes  = $cinq->map(function ($p) {
                $brut = trim($p->cotisant ?? '');
                // Nom absent ou générique → afficher le numéro de téléphone du paiement.
                $nom  = ($brut === '' || $brut === 'Client') ? ($p->numero_tel ?? '—') : $brut;
                return \Carbon\Carbon::parse($p->updated_at)->format('d/m') .
                    ' · ' . $nom .
                    ' · *' . number_format((int) $p->montant, 0, ',', ' ') . ' FCFA*';
            })->implode("\n");

            $suite = $nbTotal > 5
                ? "\n_... et " . ($nbTotal - 5) . " transaction(s) supplémentaire(s) — consultez le PDF pour l'historique complet._"
                : '';

            $this->session->set($numero, 'gerer.historique', $data);

            try {
                $pdfUrl = $this->gererCagnotteSvc->genererHistoriquePdf($cagnotte);
                $pdfBloc = "————————————————\n📄 *Historique complet — PDF*\n{$pdfUrl}";
            } catch (\Throwable $e) {
                Log::error('historique cotisation: échec PDF', ['err' => $e->getMessage()]);
                $pdfBloc = "❌ _Impossible de générer le PDF. Réessayez plus tard._";
            }

            return <<<TXT
            📊 *Historique — {$cagnotte->titre}*
            Total collecté : *{$total} FCFA* · {$nbTotal} transaction(s)

            {$lignes}{$suite}

            {$pdfBloc}

            0️⃣  Retour menu
            TXT;
        }

        if ($texte === '2') {
            $collecte = (int) $cagnotte->montant_collecte;

            if ($collecte <= 0) {
                return $this->erreurEtMenu($numero, "❌ Solde nul — aucun reversement possible.");
            }

            $collecteFmt = number_format($collecte, 0, ',', ' ');
            $this->session->set($numero, 'gerer.revers.dest', $data);

            return <<<TXT
            💸 *Initier un reversement*
            Solde disponible : *{$collecteFmt} FCFA*

            Vers quel numéro ?

            1️⃣  *Mon numéro* Mobile Money
            2️⃣  *Autre numéro*

            #️⃣ _pour revenir en arrière_
            TXT;
        }

        return "⚠️ Tapez *1*, *2*, *3* ou *4*.\n\n#️⃣ _pour revenir en arrière_";
    }

    // ── 4 — Gérer > Tontine ───────────────────────────────────────────────────

    /**
     * Affiche le menu de gestion d'une tontine selon son état courant.
     *
     * Trois états possibles (stockés dans la session sous 'tontine_etat') :
     *   - 'attente' : tontine incomplète, pas encore pleine
     *   - 'pleine'  : tous les membres inscrits, prête à démarrer
     *   - 'demarree': tontine active (date_debut renseignée ou solde > 0)
     *
     * Note sur le comptage :
     *   inscrits = nombre_inscrits + 1 (le +1 = le créateur qui est dans
     *   tondo_participants mais PAS comptabilisé dans nombre_inscrits).
     *
     * @param  string       $numero   Numéro E.164
     * @param  TondoCagnotte $cagnotte Tontine à afficher
     * @param  array        $data     Données de session
     * @return string
     */
    private function afficherMenuTontine(string $numero, TondoCagnotte $cagnotte, array $data): string
    {
        // +1 : le créateur est dans tondo_participants mais pas dans nombre_inscrits
        $inscrits = (int) $cagnotte->nombre_inscrits + 1;
        $max      = (int) $cagnotte->nombre_participants;
        // Tontine démarrée si date_debut est renseignée OU si des fonds ont déjà été collectés
        $lancee   = ! is_null($cagnotte->date_debut) || (int) $cagnotte->montant_collecte > 0;

        $etat = $lancee ? 'demarree' : ($inscrits >= $max ? 'pleine' : 'attente');

        $this->session->set($numero, 'gerer.tontine', array_merge($data, [
            'tontine_etat' => $etat,
        ]));

        $titre = $cagnotte->titre;
        $ref   = $cagnotte->reference;

        if ($lancee) {
            return <<<TXT
            🔄 *{$titre}* · N°{$ref}
            Membres : {$inscrits}/{$max} · Tontine en cours

            Que souhaitez-vous faire ?

            1️⃣  Historique des transactions
            2️⃣  Retour au menu précédant
            3️⃣  Menu principal

            _Tapez le numéro de votre choix._
            TXT;
        }

        if ($inscrits >= $max) {
            return <<<TXT
            ⏳ *{$titre}* · N°{$ref}
            Membres : {$inscrits}/{$max} ✅ Complet — Prête à démarrer !

            Que souhaitez-vous faire ?

            1️⃣  Démarrer la tontine
            2️⃣  Éditer l'ordre des membres
            3️⃣  Supprimer la tontine
            4️⃣  Retour au menu précédant
            5️⃣  Menu principal

            _Tapez le numéro de votre choix._
            TXT;
        }

        $manquants = $max - $inscrits;
        return <<<TXT
        ⏳ *{$titre}* · N°{$ref}
        Membres : {$inscrits}/{$max} _(il manque {$manquants})_

        Que souhaitez-vous faire ?

        1️⃣  Supprimer la tontine
        2️⃣  Retour au menu précédant
        3️⃣  Menu principal

        _Tapez le numéro de votre choix._
        TXT;
    }

    /**
     * Dispatch des actions du menu tontine selon son état.
     * L'état ('attente', 'pleine', 'demarree') est lu depuis la session.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Choix saisi
     * @return string
     */
    private function handleGererTontine(string $numero, string $texte): string
    {
        $data     = $this->session->data($numero);
        $etat     = $data['tontine_etat'] ?? 'attente';
        $choix    = trim($texte);
        $cagnotte = TondoCagnotte::find($data['cagnotte_id'] ?? null);

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Session expirée. Recommencez.");
        }

        if ($etat === 'demarree') {
            return match ($choix) {
                '1' => $this->demarrerHistoriqueTontine($numero, $cagnotte, $data),
                '2' => $this->retourListeCagnottes($numero, $data),
                '3' => $this->afficherMenu($numero),
                default => "⚠️ Tapez *1*, *2* ou *3*.\n\n#️⃣ _pour revenir en arrière_",
            };
        }

        if ($etat === 'pleine') {
            return match ($choix) {
                '1' => $this->executerDemarrerTontine($numero, $cagnotte),
                '2' => $this->demarrerEditionOrdre($numero, $cagnotte, $data),
                '3' => $this->executerSupprimerTontine($numero, $cagnotte),
                '4' => $this->retourListeCagnottes($numero, $data),
                '5' => $this->afficherMenu($numero),
                default => "⚠️ Tapez un chiffre de *1* à *5*.\n\n#️⃣ _pour revenir en arrière_",
            };
        }

        // etat 'attente' (pas pleine)
        return match ($choix) {
            '1' => $this->executerSupprimerTontine($numero, $cagnotte),
            '2' => $this->retourListeCagnottes($numero, $data),
            '3' => $this->afficherMenu($numero),
            default => "⚠️ Tapez *1*, *2* ou *3*.\n\n#️⃣ _pour revenir en arrière_",
        };
    }

    /**
     * Marque la tontine comme démarrée (date_debut = now()) et réaffiche son menu.
     *
     * @param  string       $numero   Numéro E.164
     * @param  TondoCagnotte $cagnotte Tontine à démarrer
     * @return string
     */
    private function executerDemarrerTontine(string $numero, TondoCagnotte $cagnotte): string
    {
        DB::table(project_table('cagnottes'))->where('id', $cagnotte->id)->update([
            'date_debut' => now(),
            'updated_at' => now(),
        ]);

        $cagnotte->refresh();
        $data = $this->session->data($numero);

        return <<<TXT
        🎉 *Tontine lancée !*

        La tontine *{$cagnotte->titre}* est maintenant active.

        TXT . "\n" . $this->afficherMenuTontine($numero, $cagnotte, $data);
    }

    /**
     * Clôture une tontine (statut → 'cloturee') et retourne la liste des cagnottes.
     * Utilisée pour les tontines en attente ou pleines (avant démarrage).
     *
     * @param  string       $numero   Numéro E.164
     * @param  TondoCagnotte $cagnotte Tontine à supprimer
     * @return string
     */
    private function executerSupprimerTontine(string $numero, TondoCagnotte $cagnotte): string
    {
        DB::table(project_table('cagnottes'))->where('id', $cagnotte->id)->update([
            'statut'     => 'cloturee',
            'updated_at' => now(),
        ]);

        $data = $this->session->data($numero);

        return <<<TXT
        ✅ *Tontine supprimée.*

        La tontine *{$cagnotte->titre}* a bien été supprimée.

        TXT . "\n" . $this->retourListeCagnottes($numero, $data);
    }

    /**
     * Affiche la liste ordonnée des membres et invite à saisir les permutations.
     * Les IDs des membres sont stockés en session pour validation ultérieure.
     *
     * @param  string       $numero   Numéro E.164
     * @param  TondoCagnotte $cagnotte Tontine concernée
     * @param  array        $data     Données de session
     * @return string
     */
    private function demarrerEditionOrdre(string $numero, TondoCagnotte $cagnotte, array $data): string
    {
        $participants = DB::table(project_table('participants'))
            ->where('cagnotte_id', $cagnotte->id)
            ->orderByRaw('COALESCE(ordre_passage, 9999)')
            ->orderBy('created_at')
            ->select(['id', 'nom', 'prenom'])
            ->get();

        $ids   = $participants->pluck('id')->toArray();
        $liste = $participants->values()->map(fn ($p, $i) =>
            ($i + 1) . '. ' . mb_strtoupper($p->nom) . ' ' . ucfirst(mb_strtolower($p->prenom))
        )->implode("\n");

        $n = count($ids);

        $this->session->set($numero, 'gerer.tontine.ordre', array_merge($data, [
            'membre_ids' => $ids,
        ]));

        return <<<TXT
        📋 *Ordre actuel des membres*

        {$liste}

        Envoyez les paires *position actuelle - nouvelle position* (une par ligne) :
        _Exemple :_
        5-1
        2-3
        4-2
        1-4
        3-5

        _(Toutes les {$n} positions doivent être couvertes.)_

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Applique les permutations d'ordre de passage fournies par le gérant.
     *
     * Format attendu : une paire X-Y par ligne (position actuelle - nouvelle position).
     * Toutes les N positions doivent être couvertes (bijection complète).
     * Validation :
     *   - Format X-Y requis pour chaque ligne.
     *   - Positions dans la plage [1, N].
     *   - Chaque source et destination apparaît exactement une fois (pas de doublon).
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Paires de permutations (une par ligne, ex : "3-1\n1-3")
     * @return string
     */
    private function handleGererTontineOrdre(string $numero, string $texte): string
    {
        $data = $this->session->data($numero);
        $ids  = $data['membre_ids'] ?? [];
        $n    = count($ids);

        $lignes = array_values(array_filter(array_map('trim', explode("\n", $texte))));
        $pairs  = [];

        foreach ($lignes as $ligne) {
            if (! preg_match('/^(\d+)-(\d+)$/', $ligne, $m)) {
                return "⚠️ Format invalide : *{$ligne}*\nUtilisez *X-Y* (ex: `3-1`).\n\n#️⃣ _pour revenir en arrière_";
            }
            $ancien  = (int) $m[1];
            $nouveau = (int) $m[2];
            if ($ancien < 1 || $ancien > $n || $nouveau < 1 || $nouveau > $n) {
                return "⚠️ Position hors plage dans *{$ligne}* (1 à {$n}).\n\n#️⃣ _pour revenir en arrière_";
            }
            $pairs[$ancien] = $nouveau;
        }

        if (count($pairs) !== $n) {
            return "⚠️ Envoyez exactement *{$n}* paires (une par membre).\n\n#️⃣ _pour revenir en arrière_";
        }

        // Vérifier la bijection : chaque position source et destination doit être unique
        $sources = array_keys($pairs);
        $dests   = array_values($pairs);
        if (count(array_unique($sources)) !== $n || count(array_unique($dests)) !== $n) {
            return "⚠️ Chaque position doit apparaître exactement *une fois* en source et en destination.\n\n#️⃣ _pour revenir en arrière_";
        }

        foreach ($pairs as $ancien => $nouveau) {
            $id = $ids[$ancien - 1] ?? null;
            if ($id) {
                DB::table(project_table('participants'))
                    ->where('id', $id)
                    ->update(['ordre_passage' => $nouveau, 'updated_at' => now()]);
            }
        }

        $cagnotte = TondoCagnotte::find($data['cagnotte_id'] ?? null);

        return "✅ *Ordre mis à jour !*\n\n" . $this->afficherMenuTontine($numero, $cagnotte, $data);
    }

    /**
     * Affiche l'historique des 5 dernières transactions d'une tontine
     * avec génération du PDF complet.
     *
     * @param  string       $numero   Numéro E.164
     * @param  TondoCagnotte $cagnotte Tontine concernée
     * @param  array        $data     Données de session
     * @return string
     */
    private function demarrerHistoriqueTontine(string $numero, TondoCagnotte $cagnotte, array $data): string
    {
        $paiements = $this->gererCagnotteSvc->historiquePaiements($cagnotte);

        if ($paiements->isEmpty()) {
            $this->session->set($numero, 'gerer.tontine', $data);
            return <<<TXT
            📊 *Historique — {$cagnotte->titre}*

            Aucune transaction confirmée pour le moment.

            1️⃣  Retour au menu précédant
            2️⃣  Menu principal

            _Tapez le numéro de votre choix._
            TXT;
        }

        $nbTotal = $paiements->count();
        $total   = number_format((int) $paiements->sum('montant'), 0, ',', ' ');
        $cinq    = $paiements->take(5);
        $lignes  = $cinq->map(function ($p) {
            $brut = trim($p->cotisant ?? '');
            // Nom absent ou générique → afficher le numéro de téléphone du paiement.
            $nom  = ($brut === '' || $brut === 'Client') ? ($p->numero_tel ?? '—') : $brut;
            return \Carbon\Carbon::parse($p->updated_at)->format('d/m') .
                ' · ' . $nom .
                ' · *' . number_format((int) $p->montant, 0, ',', ' ') . ' FCFA*';
        })->implode("\n");

        $suite = $nbTotal > 5
            ? "\n_... et " . ($nbTotal - 5) . " transaction(s) supplémentaire(s) — consultez le PDF pour l'historique complet._"
            : '';

        $this->session->set($numero, 'gerer.tontine.hist', $data);

        try {
            $pdfUrl = $this->gererCagnotteSvc->genererHistoriquePdf($cagnotte);
            $pdfBloc = "————————————————\n📄 *Historique complet — PDF*\n{$pdfUrl}";
        } catch (\Throwable $e) {
            Log::error('historique tontine: échec PDF', ['err' => $e->getMessage()]);
            $pdfBloc = "❌ _Impossible de générer le PDF. Réessayez plus tard._";
        }

        return <<<TXT
        📊 *Historique — {$cagnotte->titre}*
        Total : *{$total} FCFA* · {$nbTotal} transaction(s)

        {$lignes}{$suite}

        {$pdfBloc}

        0️⃣  Retour menu
        TXT;
    }

    /**
     * Gère les actions depuis l'écran historique d'une tontine (retour menu ou retour principal).
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   "0" (retour menu tontine) ou "3" (menu principal)
     * @return string
     */
    private function handleGererTontineHistorique(string $numero, string $texte): string
    {
        $data     = $this->session->data($numero);
        $cagnotte = TondoCagnotte::find($data['cagnotte_id'] ?? null);

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Session expirée. Recommencez.");
        }

        if ($texte === '0') {
            $cagnotte->refresh();
            return $this->afficherMenuTontine($numero, $cagnotte, $data);
        }

        if ($texte === '3') {
            return $this->afficherMenu($numero);
        }

        return "⚠️ Tapez *1*, *2* ou *3*.\n\n#️⃣ _pour revenir en arrière_";
    }

    /**
     * Retourne à la liste des cagnottes gérées.
     * Récupère l'utilisateur depuis la session et réaffiche la liste.
     *
     * @param  string $numero  Numéro E.164
     * @param  array  $data    Données de session (doit contenir user_id)
     * @return string
     */
    private function retourListeCagnottes(string $numero, array $data): string
    {
        $user = TondoUser::find($data['user_id'] ?? null);
        if (! $user) {
            return $this->afficherMenu($numero);
        }
        return $this->afficherListeCagnottes($numero, $user, $data);
    }

    /**
     * Gère les actions depuis l'écran historique d'une cagnotte.
     * "0" → retour menu cagnotte.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   "0" pour retourner
     * @return string
     */
    private function handleGererHistorique(string $numero, string $texte): string
    {
        $data     = $this->session->data($numero);
        $cagnotte = TondoCagnotte::find($data['cagnotte_id'] ?? null);

        if ($texte === '0') {
            return $this->retourMenuCagnotte($numero, $cagnotte, $data);
        }

        return "⚠️ Tapez *0* pour revenir au menu.\n\n#️⃣ _pour revenir en arrière_";
    }

    /**
     * Traite le choix de destination du reversement (1=mon numéro, 2=autre numéro).
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   "1" ou "2"
     * @return string
     */
    private function handleGererReversementDest(string $numero, string $texte): string
    {
        $data = $this->session->data($numero);

        if ($texte === '1') {
            $this->session->set($numero, 'gerer.revers.mont', array_merge($data, [
                'revers_numero' => $data['numero_payeur'],
            ]));
            $masque = $this->maskPhoneNum($data['numero_payeur'] ?? '');
            return $this->demanderMontantReversement($masque);
        }

        if ($texte === '2') {
            $this->session->set($numero, 'gerer.revers.num', $data);
            return "Entrez le *numéro* Mobile Money du bénéficiaire :\n_(format : *0XXXXXXXX*)_\n\n#️⃣ _pour revenir en arrière_";
        }

        return "⚠️ Tapez *1* pour Mon numéro ou *2* pour Autre numéro.\n\n#️⃣ _pour revenir en arrière_";
    }

    /**
     * Collecte le numéro du bénéficiaire alternatif pour le reversement.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Numéro Mobile Money saisi
     * @return string
     */
    private function handleGererReversementNum(string $numero, string $texte): string
    {
        $numeroSaisi = $this->normaliserNumero($texte);
        if (! $numeroSaisi) {
            return "⚠️ Numéro invalide. Format : *0XXXXXXXX*\n\n#️⃣ _pour revenir en arrière_";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'gerer.revers.mont', array_merge($data, [
            'revers_numero' => $numeroSaisi,
        ]));

        $masque = $this->maskPhoneNum($numeroSaisi);
        return $this->demanderMontantReversement($masque);
    }

    /**
     * Retourne le message demandant le montant à reverser.
     *
     * @param  string $masque  Numéro bénéficiaire masqué (pour affichage)
     * @return string
     */
    private function demanderMontantReversement(string $masque): string
    {
        return <<<TXT
        Bénéficiaire : *{$masque}*

        Quel *montant* souhaitez-vous reverser ? (en FCFA)
        _(min 100 — ne peut pas dépasser le solde disponible)_

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Collecte le montant du reversement, vérifie qu'il ne dépasse pas le solde,
     * puis envoie un OTP de confirmation au gérant.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Montant saisi
     * @return string
     */
    private function handleGererReversementMontant(string $numero, string $texte): string
    {
        $montant = (int) preg_replace('/\D/', '', $texte);
        if ($montant < 100) {
            return "⚠️ Montant minimum : *100 FCFA*.\n\n#️⃣ _pour revenir en arrière_";
        }

        $data     = $this->session->data($numero);
        $cagnotte = TondoCagnotte::find($data['cagnotte_id'] ?? null);

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Session expirée. Recommencez.");
        }

        if ((int) $cagnotte->montant_collecte < $montant) {
            $dispo = number_format((int) $cagnotte->montant_collecte, 0, ',', ' ');
            return "⚠️ Solde insuffisant. Disponible : *{$dispo} FCFA*.\n\n#️⃣ _pour revenir en arrière_";
        }

        $numeroGerant = $data['numero_payeur'] ?? '';
        [$otp, $hint] = $this->envoyerOtp($numeroGerant);

        $this->session->set($numero, 'gerer.revers.otp', array_merge($data, [
            'revers_montant' => $montant,
            'otp'            => $otp,
        ]));

        $masque     = $this->maskPhoneNum($data['revers_numero'] ?? '');
        $montantFmt = number_format($montant, 0, ',', ' ');
        $gerantNum  = $this->maskPhoneNum($numeroGerant);

        return <<<TXT
        🔐 *Confirmation requise*

        Reversement de *{$montantFmt} FCFA* vers *{$masque}*

        Un code a été envoyé au *{$gerantNum}*.{$hint}
        Entrez le code à 6 chiffres pour valider :

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Valide l'OTP du gérant et exécute le reversement.
     * En cas d'échec RuntimeException → message d'erreur + retour menu cagnotte.
     * En cas d'erreur inattendue → log critical + message générique.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Code OTP à 6 chiffres
     * @return string
     */
    private function handleGererReversementOtp(string $numero, string $texte): string
    {
        $data = $this->session->data($numero);
        $code = trim($texte);

        if (! preg_match('/^\d{6}$/', $code)) {
            return "⚠️ Entrez le code à *6 chiffres*.\n\n#️⃣ _pour revenir en arrière_";
        }

        if (! $this->verifierOtp($data['numero_payeur'] ?? '', $code, $data['otp'] ?? '')) {
            return "❌ Code incorrect ou expiré.\nRessayez ou #️⃣ pour revenir en arrière.";
        }

        // OTP valide → appeler GererCagnotteService::initierReversement
        $cagnotte = TondoCagnotte::find($data['cagnotte_id'] ?? null);
        $gerant   = TondoUser::find($data['user_id'] ?? null);

        if (! $cagnotte || ! $gerant) {
            return $this->erreurEtMenu($numero, "❌ Session expirée. Recommencez.");
        }

        $numeroRetrait = $data['revers_numero'] ?? '';
        $montant       = (int) ($data['revers_montant'] ?? 0);
        $masque        = $this->maskPhoneNum($numeroRetrait);

        try {
            $result = $this->gererCagnotteSvc->initierReversement(
                cagnotte:   $cagnotte,
                gerant:     $gerant,
                numeroE164: $numeroRetrait,
                montant:    $montant,
            );
        } catch (\RuntimeException $e) {
            return "❌ " . $e->getMessage() . "\n\n" . $this->retourMenuCagnotte($numero, $cagnotte, $data);
        } catch (\Throwable $e) {
            Log::error('handleGererReversementOtp: erreur inattendue', ['err' => $e->getMessage()]);
            return "❌ Erreur technique. Contactez support@tonji.ga.\n\n" . $this->retourMenuCagnotte($numero, $cagnotte, $data);
        }

        $montantFmt = number_format($result['montant'], 0, ',', ' ');

        return <<<TXT
        ✅ *Reversement effectué !*

        Montant : *{$montantFmt} FCFA*
        Bénéficiaire : *{$masque}*
        Référence : `{$result['trans_id']}`

        TXT . "\n" . $this->retourMenuCagnotte($numero, $cagnotte, $data);
    }

    // ── 4bis — Gérer > Fermer cotisation ──────────────────────────────────────

    /**
     * Gère l'écran de confirmation de fermeture d'une cagnotte.
     *
     * Deux cas selon le solde :
     *   - Solde = 0 : confirmation simple (1=fermer, 2=annuler).
     *   - Solde > 0 : le gérant choisit où verser le solde avant fermeture.
     *     1=numéro de retrait enregistré → OTP direct
     *     2=autre numéro → étape gerer.fermer.num
     *     3=annuler
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Choix saisi
     * @return string
     */
    private function handleGererFermerConfirm(string $numero, string $texte): string
    {
        $data     = $this->session->data($numero);
        $cagnotte = TondoCagnotte::find($data['cagnotte_id'] ?? null);

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Session expirée. Recommencez.");
        }

        $soldeZero = (bool) ($data['fermer_solde_zero'] ?? false);

        if ($soldeZero) {
            if ($texte === '1') {
                DB::table(project_table('cagnottes'))->where('id', $cagnotte->id)->update([
                    'statut'     => 'cloturee',
                    'updated_at' => now(),
                ]);
                return "✅ *Cagnotte clôturée.*\n\nLa cagnotte *{$cagnotte->titre}* a bien été fermée.\n\n"
                    . $this->retourListeCagnottes($numero, $data);
            }
            if ($texte === '2') {
                return $this->retourMenuCagnotte($numero, $cagnotte, $data);
            }
            return "⚠️ Tapez *1* pour confirmer ou *2* pour annuler.\n\n#️⃣ _pour revenir en arrière_";
        }

        // Solde > 0 : choisir la destination avant fermeture
        if ($texte === '1') {
            // Utiliser le numéro de retrait enregistré (immutable depuis la création)
            $dest         = $cagnotte->numero_retrait ?? ($data['numero_payeur'] ?? '');
            $numeroGerant = $data['numero_payeur'] ?? '';
            [$otp, $hint] = $this->envoyerOtp($numeroGerant);

            $this->session->set($numero, 'gerer.fermer.otp', array_merge($data, [
                'fermer_numero' => $dest,
                'otp'           => $otp,
            ]));

            $masque    = $this->maskPhoneNum($dest);
            $gerantNum = $this->maskPhoneNum($numeroGerant);
            $soldeFmt  = number_format((int) $cagnotte->montant_collecte, 0, ',', ' ');

            return <<<TXT
            🔐 *Confirmation requise*

            Fermeture + reversement de *{$soldeFmt} FCFA* vers *{$masque}*

            Un code a été envoyé au *{$gerantNum}*.{$hint}
            Entrez le code à 6 chiffres pour valider :

            #️⃣ _pour revenir en arrière_
            TXT;
        }

        if ($texte === '2') {
            $this->session->set($numero, 'gerer.fermer.num', $data);
            return "Entrez le *numéro* Mobile Money du bénéficiaire :\n_(format : *0XXXXXXXX*)_\n\n#️⃣ _pour revenir en arrière_";
        }

        if ($texte === '3') {
            return $this->retourMenuCagnotte($numero, $cagnotte, $data);
        }

        return "⚠️ Tapez *1*, *2* ou *3*.\n\n#️⃣ _pour revenir en arrière_";
    }

    /**
     * Collecte le numéro alternatif de destination lors de la fermeture avec solde.
     * Envoie un OTP de confirmation au gérant.
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Numéro bénéficiaire saisi
     * @return string
     */
    private function handleGererFermerNum(string $numero, string $texte): string
    {
        $numeroSaisi = $this->normaliserNumero($texte);
        if (! $numeroSaisi) {
            return "⚠️ Numéro invalide. Format : *0XXXXXXXX*\n\n#️⃣ _pour revenir en arrière_";
        }

        $data     = $this->session->data($numero);
        $cagnotte = TondoCagnotte::find($data['cagnotte_id'] ?? null);

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Session expirée. Recommencez.");
        }

        $numeroGerant = $data['numero_payeur'] ?? '';
        [$otp, $hint] = $this->envoyerOtp($numeroGerant);

        $this->session->set($numero, 'gerer.fermer.otp', array_merge($data, [
            'fermer_numero' => $numeroSaisi,
            'otp'           => $otp,
        ]));

        $masque    = $this->maskPhoneNum($numeroSaisi);
        $gerantNum = $this->maskPhoneNum($numeroGerant);
        $soldeFmt  = number_format((int) $cagnotte->montant_collecte, 0, ',', ' ');

        return <<<TXT
        🔐 *Confirmation requise*

        Fermeture + reversement de *{$soldeFmt} FCFA* vers *{$masque}*

        Un code a été envoyé au *{$gerantNum}*.{$hint}
        Entrez le code à 6 chiffres pour valider :

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    /**
     * Valide l'OTP de fermeture, exécute le reversement du solde restant,
     * puis clôture la cagnotte.
     *
     * En cas d'échec du reversement : la cagnotte reste ouverte (protection
     * contre une clôture sans que les fonds aient été envoyés).
     *
     * @param  string $numero  Numéro E.164
     * @param  string $texte   Code OTP à 6 chiffres
     * @return string
     */
    private function handleGererFermerOtp(string $numero, string $texte): string
    {
        $data = $this->session->data($numero);
        $code = trim($texte);

        if (! preg_match('/^\d{6}$/', $code)) {
            return "⚠️ Entrez le code à *6 chiffres*.\n\n#️⃣ _pour revenir en arrière_";
        }

        if (! $this->verifierOtp($data['numero_payeur'] ?? '', $code, $data['otp'] ?? '')) {
            return "❌ Code incorrect ou expiré.\nRessayez ou #️⃣ pour revenir en arrière.";
        }

        $cagnotte = TondoCagnotte::find($data['cagnotte_id'] ?? null);
        $gerant   = TondoUser::find($data['user_id'] ?? null);

        if (! $cagnotte || ! $gerant) {
            return $this->erreurEtMenu($numero, "❌ Session expirée. Recommencez.");
        }

        $numeroRetrait = $data['fermer_numero'] ?? '';
        // Reverser l'intégralité du solde disponible
        $montant       = (int) $cagnotte->montant_collecte;

        try {
            $result = $this->gererCagnotteSvc->initierReversement(
                cagnotte:   $cagnotte,
                gerant:     $gerant,
                numeroE164: $numeroRetrait,
                montant:    $montant,
            );
        } catch (\RuntimeException $e) {
            // Reversement échoué → ne pas clôturer, la cagnotte reste ouverte
            return "❌ " . $e->getMessage() . "\n\nLa cagnotte reste ouverte.\n\n" . $this->retourMenuCagnotte($numero, $cagnotte, $data);
        } catch (\Throwable $e) {
            Log::error('handleGererFermerOtp: erreur reversement', ['err' => $e->getMessage()]);
            return "❌ Erreur technique. Contactez support@tonji.ga.\n\nLa cagnotte reste ouverte.\n\n" . $this->retourMenuCagnotte($numero, $cagnotte, $data);
        }

        // Reversement réussi → clôturer la cagnotte
        DB::table(project_table('cagnottes'))->where('id', $cagnotte->id)->update([
            'statut'     => 'cloturee',
            'updated_at' => now(),
        ]);

        $masque     = $this->maskPhoneNum($numeroRetrait);
        $montantFmt = number_format($result['montant'], 0, ',', ' ');

        return <<<TXT
        ✅ *Cagnotte fermée !*

        *{$montantFmt} FCFA* reversés vers *{$masque}*
        Référence : `{$result['trans_id']}`

        La cagnotte *{$cagnotte->titre}* a été clôturée.

        TXT . "\n" . $this->retourListeCagnottes($numero, $data);
    }

    /**
     * Retourne au menu principal d'une cagnotte et repositionne la session à 'gerer.cagnotte'.
     *
     * @param  string       $numero   Numéro E.164
     * @param  TondoCagnotte $cagnotte Cagnotte concernée
     * @param  array        $data     Données de session
     * @return string
     */
    private function retourMenuCagnotte(string $numero, TondoCagnotte $cagnotte, array $data): string
    {
        $collecte = number_format((int) $cagnotte->montant_collecte, 0, ',', ' ');
        $ref      = $cagnotte->reference;
        $this->session->set($numero, 'gerer.cagnotte', $data);

        return <<<TXT
        💼 *{$cagnotte->titre}* · N°{$ref}
        Solde disponible : *{$collecte} FCFA*

        Que souhaitez-vous faire ?

        1️⃣  *Historique* des transactions
        2️⃣  *Initier* un reversement
        3️⃣  *Fermer* la cagnotte
        4️⃣  Retour à la liste

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    // ── Deep links TONJI [ref] ────────────────────────────────────────────────

    /**
     * Traite un deep link WhatsApp ("TONJI XXXXXX").
     *
     * Contourne l'état de session existant et injecte directement l'utilisateur
     * au bon endroit du flow selon l'état de la cagnotte :
     *
     *   - Tontine non démarrée (date_debut IS NULL) :
     *     → session 'rejoindre.numero' (inscription comme nouveau membre)
     *
     *   - Tontine démarrée (date_debut renseignée) :
     *     → session 'cotiser.numero' avec montant fixe pré-rempli
     *     (le membre doit déjà être inscrit, vérifié dans handleCotiserNumero)
     *
     *   - Cagnotte ouverte :
     *     → session 'cotiser.montant' (l'inscription se fait automatiquement au paiement)
     *
     * @param  string $numero  Numéro WhatsApp expéditeur (E.164)
     * @param  string $ref     Référence extraite du message "TONJI [ref]"
     * @return string
     */
    private function handleDeepLink(string $numero, string $ref): string
    {
        $cagnotte = TondoCagnotte::where('reference', $ref)->first();

        if (! $cagnotte || $cagnotte->statut === 'cloturee') {
            $this->session->set($numero, 'menu');
            return "❌ Cette cagnotte n'est pas disponible.\n\n" . $this->afficherMenu($numero);
        }

        // Cas 1 : tontine non démarrée → flux d'inscription
        if ($cagnotte->type === 'tontine_periodique' && is_null($cagnotte->date_debut)) {
            $this->session->set($numero, 'rejoindre.numero', [
                'cagnotte_id'  => $cagnotte->id,
                'cagnotte_ref' => $cagnotte->reference,
                'project_id'   => $cagnotte->project_id,
                'type'         => $cagnotte->type,
            ]);
            return <<<TXT
            🤝 *{$cagnotte->titre}* · N°{$ref}
            Type : Tontine

            Entrez votre *numéro de téléphone* Mobile Money
            (format : *0XXXXXXXX*).

            #️⃣ _pour revenir en arrière_
            TXT;
        }

        // Cas 2 : tontine démarrée → paiement direct au montant fixe
        if ($cagnotte->type === 'tontine_periodique' && ! is_null($cagnotte->date_debut)) {
            $montant = (int) $cagnotte->montant_par_cycle;
            $fmt     = number_format($montant, 0, ',', ' ');

            $this->session->set($numero, 'cotiser.numero', [
                'reference'      => $cagnotte->reference,
                'cagnotte_id'    => $cagnotte->id,
                'cagnotte_titre' => $cagnotte->titre,
                'type'           => $cagnotte->type,
                'project_id'     => $cagnotte->project_id,
                'montant'        => $montant,
            ]);

            return <<<TXT
            ✅ *{$cagnotte->titre}* · N°{$ref}
            Type : Tontine · Montant fixe : *{$fmt} FCFA*

            Entrez votre *numéro de téléphone* Mobile Money
            (format : *0XXXXXXXX*).

            #️⃣ _pour revenir en arrière_
            TXT;
        }

        // Cas 3 : cagnotte ouverte → paiement libre (l'inscription est automatique au paiement)
        $this->session->set($numero, 'cotiser.montant', [
            'reference'      => $cagnotte->reference,
            'cagnotte_id'    => $cagnotte->id,
            'cagnotte_titre' => $cagnotte->titre,
            'type'           => $cagnotte->type,
            'project_id'     => $cagnotte->project_id,
        ]);

        return <<<TXT
        💰 *{$cagnotte->titre}* · N°{$ref}

        Quel *montant* souhaitez-vous verser ?
        _(minimum 100 FCFA — maximum 500 000 FCFA)_

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    // ── 5 — Aide ──────────────────────────────────────────────────────────────

    /**
     * Affiche le message d'aide et de support, puis retourne au menu.
     *
     * @param  string $numero  Numéro E.164
     * @return string
     */
    private function afficherAide(string $numero): string
    {
        return <<<TXT
        ❓ *Aide & support Tonji*

        Pour toute question, problème ou réclamation, notre équipe est disponible :

        📧 *Email* : support@tonji.ga
        _(Réponse sous 24h, jours ouvrables)_

        ————————————————
        TXT . "\n" . $this->afficherMenu($numero);
    }

    // ── Utilitaires OTP ──────────────────────────────────────────────────────

    /**
     * Envoie un OTP au numéro indiqué.
     *
     * Délègue TOUJOURS à OtpService::sendOtp() (driver OTP_DRIVER). En prod le
     * driver est `paynala` → livraison SMS réelle via Wirepick (même chemin que
     * l'app mobile). Twilio n'intervient PAS dans l'OTP ; il ne sert qu'au
     * transport du chat WhatsApp (sandbox).
     *
     * AUCUN bypass de test : le bot n'accepte jamais de code statique, quel que
     * soit l'environnement.
     *
     * @param  string $numeroE164  Numéro E.164 destinataire de l'OTP
     * @return array{0: string|null, 1: string}  Toujours [null, ''] (signature conservée)
     */
    private function envoyerOtp(string $numeroE164): array
    {
        try {
            // OtpService délègue au driver configuré (paynala → Wirepick en prod).
            $this->otpService->sendOtp($numeroE164);
        } catch (\Throwable $e) {
            Log::warning('envoyerOtp: échec OtpService', [
                'driver' => $this->otpService->driver(),
                'numero' => $numeroE164,
                'err'    => $e->getMessage(),
            ]);
        }

        return [null, ''];
    }

    /**
     * Vérifie un code OTP saisi par l'utilisateur.
     *
     * Délègue TOUJOURS à OtpService::checkOtp() (driver `paynala` → vérification
     * du code stocké en cache Laravel par PaynalaOtpService). AUCUN bypass de
     * test, quel que soit l'environnement. Pas de Twilio.
     * Retourne false en cas d'exception (ne propage pas l'erreur).
     *
     * @param  string      $numeroE164  Numéro E.164 sur lequel l'OTP a été envoyé
     * @param  string      $codeSaisi   Code entré par l'utilisateur
     * @param  string|null $otpLocal    Conservé pour compat. d'appel — non utilisé
     * @return bool
     */
    private function verifierOtp(string $numeroE164, string $codeSaisi, ?string $otpLocal = null): bool
    {
        try {
            return $this->otpService->checkOtp($numeroE164, $codeSaisi);
        } catch (\Throwable $e) {
            Log::error('verifierOtp: erreur OtpService', [
                'driver' => $this->otpService->driver(),
                'err'    => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ── Utilitaires ───────────────────────────────────────────────────────────

    /**
     * Détermine si le message est un mot-clé de retour au menu.
     * Insensible à la casse. Déclenche un reset de session dans traiter().
     *
     * @param  string $texte  Message reçu (déjà trimé)
     * @return bool
     */
    private function estRetourMenu(string $texte): bool
    {
        return in_array(mb_strtolower(trim($texte)), ['#', 'menu', 'retour', 'annuler', 'cancel', 'stop'], true);
    }

    /**
     * Recherche un utilisateur par son numéro WhatsApp (expéditeur).
     * Utilise le projet tondo par défaut.
     * Méthode de convenance — préférer utilisateurParNumero() lorsque le projectId est connu.
     *
     * @param  string $numero  Numéro E.164 de l'expéditeur WhatsApp
     * @return TondoUser|null
     */
    private function utilisateur(string $numero): ?TondoUser
    {
        $suffixe   = substr(preg_replace('/\D/', '', $numero), -9);
        $projectId = $this->tondoProjectId();

        return TondoUser::where('project_id', $projectId)
            ->where('numero', 'like', "%{$suffixe}")
            ->first();
    }

    /**
     * Recherche un utilisateur par numéro E.164 et projet, avec tolérance de préfixe.
     *
     * La recherche utilise les 9 derniers chiffres du numéro (suffixe) pour tolérer
     * les variantes +241XXXXXXXX vs 0XXXXXXXX. Cette approche est possible car
     * les numéros gabonais ont toujours la même partie locale (sans préfixe).
     *
     * @param  string $numeroE164  Numéro normalisé E.164
     * @param  string $projectId   UUID du projet Tondo
     * @return TondoUser|null
     */
    private function utilisateurParNumero(string $numeroE164, string $projectId): ?TondoUser
    {
        // 9 derniers chiffres = suffixe local, identique quelle que soit la forme du préfixe
        $suffixe = substr(preg_replace('/\D/', '', $numeroE164), -9);

        return TondoUser::where('project_id', $projectId)
            ->where('numero', 'like', "%{$suffixe}")
            ->first();
    }

    /**
     * Normalise une saisie téléphonique en format E.164 (+241XXXXXXXX pour le Gabon).
     *
     * Règles de conversion (ordre de priorité) :
     *   1. Commence par '+' → conserver tel quel (déjà E.164)
     *   2. Commence par '00' → remplacer par '+' (format international alternatif)
     *   3. Commence par '0' + 9-11 chiffres → Gabon local (0XXXXXXXX → +241XXXXXXXX)
     *   4. 8 chiffres sans préfixe → Gabon court (77XXXXXX → +24177XXXXXX)
     *   5. ≥ 10 chiffres sans '+' → international complet (ex : 24177XXXXXX → +24177XXXXXX)
     *   6. Sinon → null (numéro invalide)
     *
     * @param  string $texte  Saisie brute de l'utilisateur
     * @return string|null    Numéro E.164 ou null si invalide
     */
    private function normaliserNumero(string $texte): ?string
    {
        $texte    = trim($texte);
        $chiffres = preg_replace('/\D/', '', $texte);

        if (strlen($chiffres) < 6) {
            return null;   // trop court pour être un numéro valide
        }

        // 1. Numéro international avec '+' → conserver
        if (str_starts_with($texte, '+')) {
            return '+' . $chiffres;
        }

        // 2. Format international avec '00' → remplacer par '+'
        if (str_starts_with($chiffres, '00')) {
            return '+' . substr($chiffres, 2);
        }

        // 3. Format Gabon local : 0XXXXXXXX (9 à 11 chiffres avec le zéro)
        if (str_starts_with($chiffres, '0') && strlen($chiffres) >= 9 && strlen($chiffres) <= 11) {
            return '+241' . substr($chiffres, 1);   // retire le '0', ajoute indicatif Gabon
        }

        // 4. Numéro gabonais sans préfixe : 77XXXXXX (8 chiffres)
        if (strlen($chiffres) === 8) {
            return '+241' . $chiffres;
        }

        // 5. Numéro international complet sans '+' (ex : 24177XXXXXX ou 221XXXXXXXXX)
        if (strlen($chiffres) >= 10) {
            return '+' . $chiffres;
        }

        return null;   // format non reconnu
    }

    /**
     * Retourne l'UUID du projet Tondo, mis en cache statique pour éviter
     * une requête DB répétée à chaque message reçu.
     *
     * @return string  UUID du projet (chaîne vide si introuvable)
     */
    private function tondoProjectId(): string
    {
        static $id = null;
        if ($id === null) {
            // Lecture unique depuis la DB, puis mise en cache statique pour la durée de la requête
            $id = DB::table('projects')->where('slug', 'tondo')->value('id') ?? '';
        }
        return $id;
    }

    /**
     * Inscrit un membre à une cagnotte/tontine de façon idempotente.
     * Si le membre est déjà inscrit, ne fait rien (pas d'erreur, pas de doublon).
     * Incrémente nombre_inscrits seulement si l'inscription est nouvelle.
     *
     * @param  TondoUser    $user     Membre à inscrire
     * @param  TondoCagnotte $cagnotte Cagnotte/tontine cible
     */
    private function inscrireMembre(TondoUser $user, TondoCagnotte $cagnotte): void
    {
        // Vérification d'idempotence : ne rien faire si déjà inscrit
        $deja = DB::table(project_table('participants'))
            ->where('cagnotte_id', $cagnotte->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($deja) {
            return;
        }

        DB::table(project_table('participants'))->insert([
            'id'              => (string) \Illuminate\Support\Str::uuid(),
            'project_id'      => $cagnotte->project_id,
            'cagnotte_id'     => $cagnotte->id,
            'user_id'         => $user->id,
            'nom'             => $user->nom,
            'prenom'          => $user->prenom,
            'numero_masque'   => $this->maskPhoneNum($user->numero ?? ''),
            'statut_paiement' => 'en_attente',
            'montant_paye'    => 0,
            'created_at'      => now(),
        ]);

        DB::table(project_table('cagnottes'))
            ->where('id', $cagnotte->id)
            ->increment('nombre_inscrits');
    }

    /**
     * Masque les chiffres centraux d'un numéro pour l'affichage (protection vie privée).
     *
     * Conserve le préfixe et les 2 derniers chiffres, masque le reste avec des '*'.
     * Exemple : +24177123456 → +241771****56
     *
     * Identique à CotisationService::maskPhone() et CreerCagnotteService::maskPhone() —
     * dupliqué pour éviter une dépendance circulaire entre services WhatsApp.
     *
     * @param  string $phone  Numéro E.164 ou local
     * @return string         Numéro masqué
     */
    private function maskPhoneNum(string $phone): string
    {
        // Conserver uniquement les chiffres et le '+'
        $clean = preg_replace('/[^\d+]/', '', $phone);
        if (strlen($clean) < 6) return $clean;
        // Préfixe = tout sauf les 6 derniers caractères (4 masqués + 2 visibles)
        $prefix = substr($clean, 0, strlen($clean) - 6);
        return $prefix . str_repeat('*', strlen($clean) - strlen($prefix) - 2) . substr($clean, -2);
    }
}
