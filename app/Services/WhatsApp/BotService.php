<?php

namespace App\Services\WhatsApp;

use App\Jobs\WhatsApp\VerifierPaiementJob;
use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use App\Services\ReceiptService;
use App\Services\TwilioVerifyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moteur conversationnel du bot WhatsApp Tondo.
 *
 * Machine à états pilotée par SessionService :
 *
 *  [aucune session] ──► MENU
 *
 *  MENU ──► 1 ──► cotiser.ref
 *                     │
 *                     ├─ tontine ──► cotiser.numero
 *                     │                  │
 *                     │                  ├─ non-participant ──► "pas inscrit" + menu
 *                     │                  └─ participant ──► [push] ──► cotiser.attente
 *                     │
 *                     └─ cotisation ──► cotiser.montant ──► cotiser.numero
 *                                                               │
 *                                                               ├─ connu ──► [push] ──► cotiser.attente
 *                                                               └─ inconnu ──► cotiser.nom_prenom ──► [push] ──► cotiser.attente
 *
 *        ──► 2 ──► rejoindre.ref
 *                     ├─ inconnu    ──► rejoindre.nom_prenom ──► inscription ──► menu
 *                     └─ connu      ──► inscription ──► menu
 *        ──► 3 ──► lien app web
 *        ──► 4 ──► liste cagnottes
 *        ──► 5 ──► aide
 *
 *  cotiser.attente ──► OK ──► vérif statut ──► reçu / échec / toujours en cours
 */
class BotService
{
    public function __construct(
        private SessionService       $session,
        private CotisationService    $cotisationSvc,
        private ReceiptService       $receiptSvc,
        private CreerCagnotteService $creerCagnotteSvc,
        private GererCagnotteService $gererCagnotteSvc,
        private TwilioVerifyService  $twilioVerify,
    ) {}

    // ── Point d'entrée ────────────────────────────────────────────────────────

    /**
     * Traite un message entrant et retourne la réponse.
     * Retourne soit :
     *   - string                → message texte simple
     *   - [string, string]      → [message texte, url PDF à joindre en Media]
     *
     * @return string|array{0:string,1:string}
     */
    public function traiter(string $numero, string $texte): string|array
    {
        $texte = trim($texte);
        $etape = $this->session->etape($numero);

        if ($this->estRetourMenu($texte)) {
            $this->session->reset($numero);
            return $this->afficherMenu($numero);
        }

        if ($etape === null || $texte === '') {
            return $this->premiereArrivee($numero);
        }

        return match (true) {
            $etape === 'menu'                  => $this->handleMenu($numero, $texte),
            $etape === 'cotiser.ref'            => $this->handleCotiserRef($numero, $texte),
            $etape === 'cotiser.montant'        => $this->handleCotiserMontant($numero, $texte),
            $etape === 'cotiser.numero'         => $this->handleCotiserNumero($numero, $texte),
            $etape === 'cotiser.nom_prenom'     => $this->handleCotiserNomPrenom($numero, $texte),
            $etape === 'cotiser.attente'        => $this->handleCotiserAttente($numero, $texte),
            $etape === 'rejoindre.ref'          => $this->handleRejoindreRef($numero, $texte),
            $etape === 'rejoindre.numero'       => $this->handleRejoindreNumero($numero, $texte),
            $etape === 'rejoindre.nom_prenom'   => $this->handleRejoindreNomPrenom($numero, $texte),
            str_starts_with($etape, 'creer.')  => $this->routerCreer($numero, $etape, $texte),
            str_starts_with($etape, 'gerer.')  => $this->routerGerer($numero, $etape, $texte),
            default                             => $this->afficherMenu($numero),
        };
    }

    // ── Première arrivée ──────────────────────────────────────────────────────

    private function premiereArrivee(string $numero): string
    {
        $this->session->set($numero, 'menu');
        return $this->afficherMenu($numero);
    }

    // ── Menu principal ────────────────────────────────────────────────────────

    private function erreurEtMenu(string $numero, string $message): string
    {
        $this->session->reset($numero);
        return $message . "\n\n" . $this->afficherMenu($numero);
    }

    private function afficherMenu(string $numero): string
    {
        $this->session->set($numero, 'menu');

        return <<<TXT
        🎉 *Bienvenue sur Tondo !*

        Que souhaitez-vous faire ?

        1️⃣  *Cotiser*
        2️⃣  *Rejoindre* une cagnotte
        3️⃣  *Créer* une cagnotte
        4️⃣  *Gérer* mes cagnottes
        5️⃣  *Aide* & support

        _Tapez le numéro de votre choix._
        TXT;
    }

    private function handleMenu(string $numero, string $texte): string
    {
        return match (trim($texte)) {
            '1'     => $this->demarrerCotiser($numero),
            '2'     => $this->demarrerRejoindre($numero),
            '3'     => $this->demarrerCreer($numero),
            '4'     => $this->demarrerGerer($numero),
            '5'     => $this->afficherAide($numero),
            default => $this->afficherMenu($numero),
        };
    }

    // ── 1 — Cotiser : référence ───────────────────────────────────────────────

    private function demarrerCotiser(string $numero): string
    {
        $this->session->set($numero, 'cotiser.ref');
        return <<<TXT
        💰 *Cotiser*

        Entrez la *référence* de la cagnotte
        (numéro à 6 chiffres fourni par l'organisateur).

        _Tapez_ *#️⃣* _pour revenir au menu._
        TXT;
    }

    private function handleCotiserRef(string $numero, string $texte): string
    {
        $ref      = preg_replace('/\D/', '', $texte);
        $cagnotte = $ref ? TondoCagnotte::where('reference', $ref)->first() : null;

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Référence *#{$ref}* introuvable.\nVérifiez et réessayez.");
        }

        if ($cagnotte->statut === 'cloturee') {
            return "❌ La cagnotte *{$cagnotte->titre}* est clôturée.\n\n_Tapez_ *#️⃣* _pour revenir au menu._";
        }

        // Stocker les infos cagnotte dans la session
        $this->session->set($numero, 'cotiser.montant', [
            'reference'         => $ref,
            'cagnotte_id'       => $cagnotte->id,
            'cagnotte_titre'    => $cagnotte->titre,
            'type'              => $cagnotte->type,
            'project_id'        => $cagnotte->project_id,
            'montant_par_cycle' => $cagnotte->montant_par_cycle,
        ]);

        // Tontine : bloquer si pas encore complète
        if ($cagnotte->type === 'tontine_periodique') {
            // +1 car le créateur est dans tondo_participants mais pas dans nombre_inscrits
            $manquants = ($cagnotte->nombre_participants ?? 0) - (($cagnotte->nombre_inscrits ?? 0) + 1);
            if ($manquants > 0) {
                return $this->erreurEtMenu($numero, <<<TXT
                ⏳ *La tontine n'a pas encore démarré.*

                Il manque encore *{$manquants} participant(s)* avant le lancement.
                La cotisation sera ouverte une fois tous les membres inscrits.
                TXT);
            }
        }

        // Tontine → montant fixe, pas besoin de le demander
        if ($cagnotte->type === 'tontine_periodique' && $cagnotte->montant_par_cycle) {
            $fmt = number_format((int) $cagnotte->montant_par_cycle, 0, ',', ' ');
            // Passer directement à la demande de numéro
            $this->session->set($numero, 'cotiser.numero', [
                'reference'      => $ref,
                'cagnotte_id'    => $cagnotte->id,
                'cagnotte_titre' => $cagnotte->titre,
                'type'           => $cagnotte->type,
                'project_id'     => $cagnotte->project_id,
                'montant'        => (int) $cagnotte->montant_par_cycle,
            ]);

            return <<<TXT
            ✅ *{$cagnotte->titre}* · #{$ref}
            Type : Tontine · Montant fixe : *{$fmt} FCFA*

            Entrez votre *numéro de téléphone* Mobile Money
            (format : *0XXXXXXXX*).

            _Tapez_ *#️⃣* _pour revenir au menu._
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
        ✅ *{$cagnotte->titre}* · #{$ref}
        Type : Cotisation

        Quel *montant* souhaitez-vous cotiser ?
        _(minimum 100 FCFA — maximum 500 000 FCFA)_

        _Tapez_ *#️⃣* _pour revenir au menu._
        TXT;
    }

    // ── 1 — Cotiser : montant (cotisation uniquement) ─────────────────────────

    private function handleCotiserMontant(string $numero, string $texte): string
    {
        $montant = (int) preg_replace('/\D/', '', $texte);

        if ($montant < 100) {
            return "⚠️ Montant minimum : *100 FCFA*.\nEntrez un montant valide, ou tapez *#️⃣* pour annuler.";
        }

        if ($montant > 500_000) {
            return "⚠️ Montant maximum par transaction : *500 000 FCFA*.\nEntrez un montant valide, ou tapez *#️⃣* pour annuler.";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'cotiser.numero', array_merge($data, ['montant' => $montant]));

        return <<<TXT
        💵 Montant : *{$montant} FCFA*

        Entrez votre *numéro de téléphone* Mobile Money
        (format : *0XXXXXXXX*).

        _Tapez_ *#️⃣* _pour revenir au menu._
        TXT;
    }

    // ── 1 — Cotiser : numéro de téléphone ────────────────────────────────────

    private function handleCotiserNumero(string $numero, string $texte): string|array
    {
        $numeroSaisi = $this->normaliserNumero($texte);

        if (! $numeroSaisi) {
            return "⚠️ Numéro invalide.\nFormat attendu : *0XXXXXXXX*\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $data     = $this->session->data($numero);
        $type     = $data['type'] ?? '';
        $projectId = $data['project_id'] ?? $this->tondoProjectId();

        // Chercher l'utilisateur par ce numéro
        $user = $this->utilisateurParNumero($numeroSaisi, $projectId);

        // ── Tontine : vérifier participant + tontine démarrée ────────────────
        if ($type === 'tontine_periodique') {
            $estParticipant = $user && DB::table('tondo_participants')
                ->where('cagnotte_id', $data['cagnotte_id'])
                ->where('user_id', $user->id)
                ->exists();

            if (! $estParticipant) {
                return $this->erreurEtMenu($numero, <<<TXT
                ❌ *Vous n'êtes pas encore inscrit à cette tontine.*

                Rejoignez-la d'abord en choisissant l'option *2️⃣* du menu.
                TXT);
            }

            // Participant confirmé → push
            return $this->lancerPaiement($numero, $user, $data, $numeroSaisi);
        }

        // ── Cotisation : utilisateur connu ────────────────────────────────────
        if ($user) {
            return $this->lancerPaiement($numero, $user, $data, $numeroSaisi);
        }

        // ── Cotisation : nouvel utilisateur → demander nom + prénom ──────────
        $this->session->set($numero, 'cotiser.nom_prenom', array_merge($data, [
            'numero_payeur' => $numeroSaisi,
        ]));

        return <<<TXT
        👤 *Nouveau sur Tondo*

        Vous n'avez pas encore de compte. On va en créer un rapidement.

        Entrez votre *nom* puis votre *prénom*, chacun sur une ligne :

        _Exemple :_
        MBOULA
        Jean

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    // ── 1 — Cotiser : nom + prénom (nouveau compte light) ────────────────────

    private function handleCotiserNomPrenom(string $numero, string $texte): string|array
    {
        $lignes = array_filter(array_map('trim', explode("\n", $texte)));

        if (count($lignes) < 2) {
            return <<<TXT
            ⚠️ Format incorrect.
            Entrez votre *nom* puis votre *prénom*, chacun sur une ligne :

            _Exemple :_
            MBOULA
            Jean

            _Tapez_ *#️⃣* _pour annuler._
            TXT;
        }

        $lignes  = array_values($lignes);
        $nom     = mb_strtoupper(trim($lignes[0]));
        $prenom  = ucfirst(mb_strtolower(trim($lignes[1])));
        $data    = $this->session->data($numero);
        $projectId = $data['project_id'] ?? $this->tondoProjectId();

        // Créer le compte light
        $user = $this->cotisationSvc->creerCompteLight(
            nom: $nom,
            prenom: $prenom,
            numeroE164: $data['numero_payeur'],
            projectId: $projectId,
        );

        return $this->lancerPaiement($numero, $user, $data, $data['numero_payeur']);
    }

    // ── 1 — Cotiser : initier le paiement et attendre ────────────────────────

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
            return "❌ Une erreur technique est survenue. Veuillez réessayer.\n\n_Tapez_ *#️⃣* _pour revenir au menu._";
        }
    }

    private function _lancerPaiement(string $numero, TondoUser $user, array $data, string $numeroPayeur): string|array
    {
        $cagnotte = TondoCagnotte::find($data['cagnotte_id']);

        if (! $cagnotte) {
            $this->session->reset($numero);
            return "❌ Erreur : cagnotte introuvable.\n\n_Tapez_ *#️⃣* _pour revenir au menu._";
        }

        // Utiliser le numéro saisi comme numéro de paiement
        $userPourPaiement         = clone $user;
        $userPourPaiement->numero = $numeroPayeur;

        $resultat = $this->cotisationSvc->initier($userPourPaiement, $cagnotte, (int) $data['montant']);

        if ($resultat['statut'] === 'erreur') {
            $this->session->reset($numero);
            return "❌ Erreur lors de l'initiation du paiement : {$resultat['message']}\n\n_Tapez_ *#️⃣* _pour revenir au menu._";
        }

        $prenom   = ucfirst(mb_strtolower($user->prenom));
        $montantFmt = number_format($data['montant'], 0, ',', ' ');

        // Paiement immédiat (mock)
        if ($resultat['statut'] === 'succes') {
            $this->session->set($numero, 'menu');
            return $this->recu($user, $cagnotte, $resultat);
        }

        // Paiement Airtel → en attente de confirmation
        $this->session->set($numero, 'cotiser.attente', [
            'trans_id'       => $resultat['trans_id'],
            'project_id'     => $cagnotte->project_id,
            'cagnotte_titre' => $cagnotte->titre,
            'reference'      => $cagnotte->reference,
            'montant'        => $data['montant'],
            'prenom'         => $prenom,
            'user_id'        => $user->id,
        ]);

        // Surveillance automatique : job qui poll toutes les 30s pendant 3 min max
        VerifierPaiementJob::dispatch(
            transId:     $resultat['trans_id'],
            numeroWa:    $numero,
            projectId:   $cagnotte->project_id,
            cagnotteRef: $cagnotte->reference,
            montant:     (int) $data['montant'],
            prenom:      $prenom,
            userId:      $user->id,
        )->delay(now()->addSeconds(30));

        return <<<TXT
        ⏳ Bonjour *{$prenom}* !

        Un message de confirmation a été envoyé sur votre téléphone *{$numeroPayeur}*.

        👉 *Validez le paiement de {$montantFmt} FCFA sur votre Mobile Money.*

        Vous recevrez la confirmation *automatiquement* dès validation (délai max 3 min).
        Tapez *OK* si vous souhaitez vérifier manuellement.

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    // ── 1 — Cotiser : vérification après push ────────────────────────────────

    private function handleCotiserAttente(string $numero, string $texte): string|array
    {
        $data    = $this->session->data($numero);
        $transId = $data['trans_id'] ?? null;

        if (! $transId) {
            $this->session->reset($numero);
            return $this->afficherMenu($numero);
        }

        if (strtolower(trim($texte)) !== 'ok') {
            return <<<TXT
            ⏳ Paiement en attente de confirmation.

            Validez le paiement sur votre Mobile Money puis tapez *OK*.

            _Tapez_ *#️⃣* _pour annuler._
            TXT;
        }

        $statut = $this->cotisationSvc->verifierStatut($transId, $data['project_id']);

        if ($statut === 'succes') {
            $this->session->set($numero, 'menu');
            $cagnotte = TondoCagnotte::where('reference', $data['reference'])->first();
            $user     = TondoUser::find($data['user_id']);

            // Reçu PDF envoyé en message séparé dès qu'il est prêt.
            \App\Jobs\WhatsApp\EnvoyerRecuJob::dispatch(
                numeroWa:    $numero,
                userId:      $data['user_id'] ?? null,
                cagnotteRef: $data['reference'] ?? null,
                transId:     $transId,
                montant:     (int) ($data['montant'] ?? 0),
            )->delay(now()->addSeconds(4));

            return $this->recu($user, $cagnotte, [
                'trans_id'    => $transId,
                'montant_net' => $data['montant'],
            ]);
        }

        if ($statut === 'echec') {
            $this->session->reset($numero);
            return <<<TXT
            ❌ *Paiement échoué ou refusé.*

            ⚠️ _Si vous constatez un prélèvement sur votre compte sans confirmation de notre part, contactez-nous immédiatement à support@tondo.ga ou appelez le *+241 01 XX XX XX*. Nous traiterons votre remboursement sous 24h._

            TXT . "\n" . $this->afficherMenu($numero);
        }

        // Toujours en cours
        return <<<TXT
        ⏳ Paiement toujours en cours de traitement.

        Attendez quelques secondes et tapez *OK* à nouveau.

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    // ── Reçu Tondo (PDF) ─────────────────────────────────────────────────────

    /**
     * Génère le PDF et retourne [message_texte, pdf_url].
     * Le WebhookController inclura le PDF en <Media> dans le TwiML.
     */
    public function recu(?TondoUser $user, ?TondoCagnotte $cagnotte, array $resultat, string $canal = 'WhatsApp'): string
    {
        $montant = number_format((int) ($resultat['montant_net'] ?? 0), 0, ',', ' ');
        $titre   = $cagnotte ? $cagnotte->titre : '—';
        $ref     = $cagnotte ? '#' . $cagnotte->reference : '';
        $prenom  = $user ? ucfirst(mb_strtolower($user->prenom)) : '';

        return <<<TXT
        ✅ *Paiement confirmé !*

        Merci {$prenom} 🙏
        Votre cotisation de *{$montant} FCFA* pour *{$titre} {$ref}* a été enregistrée.

        ————————————————
        🎉 *Que souhaitez-vous faire ?*

        1️⃣  *Cotiser*
        2️⃣  *Rejoindre* une cagnotte
        3️⃣  *Créer* une cagnotte
        4️⃣  *Gérer* mes cagnottes
        5️⃣  *Aide* & support

        _Tapez le numéro de votre choix._
        TXT;
    }

    // ── 2 — Rejoindre ─────────────────────────────────────────────────────────

    private function demarrerRejoindre(string $numero): string
    {
        $this->session->set($numero, 'rejoindre.ref');
        return <<<TXT
        🤝 *Rejoindre une cagnotte*

        Entrez la *référence* de la cagnotte
        (numéro à 6 chiffres fourni par l'organisateur).

        _Tapez_ *#️⃣* _pour revenir au menu._
        TXT;
    }

    private function handleRejoindreRef(string $numero, string $texte): string
    {
        $ref      = preg_replace('/\D/', '', $texte);
        $cagnotte = $ref ? TondoCagnotte::where('reference', $ref)->first() : null;

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Référence *#{$ref}* introuvable.\nVérifiez et réessayez.");
        }

        $type = $cagnotte->type === 'tontine_periodique' ? 'Tontine' : 'Cotisation';

        $this->session->set($numero, 'rejoindre.numero', [
            'cagnotte_id'  => $cagnotte->id,
            'cagnotte_ref' => $ref,
            'project_id'   => $cagnotte->project_id,
            'type'         => $cagnotte->type,
        ]);

        return <<<TXT
        🤝 *{$cagnotte->titre}* · #{$ref}
        Type : {$type}

        Entrez votre *numéro de téléphone* Mobile Money
        (format : *0XXXXXXXX*).

        _Tapez_ *#️⃣* _pour revenir au menu._
        TXT;
    }

    private function handleRejoindreNumero(string $numero, string $texte): string
    {
        $numeroSaisi = $this->normaliserNumero($texte);

        if (! $numeroSaisi) {
            return "⚠️ Numéro invalide.\nFormat attendu : *0XXXXXXXX*\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $data      = $this->session->data($numero);
        $projectId = $data['project_id'] ?? $this->tondoProjectId();
        $cagnotte  = TondoCagnotte::find($data['cagnotte_id'] ?? null);

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Session expirée. Recommencez.");
        }

        $ref  = $data['cagnotte_ref'] ?? $cagnotte->reference;
        $user = $this->utilisateurParNumero($numeroSaisi, $projectId);

        // Déjà membre ?
        if ($user) {
            $dejaMembre = DB::table('tondo_participants')
                ->where('cagnotte_id', $cagnotte->id)
                ->where('user_id', $user->id)
                ->exists();

            if ($dejaMembre) {
                return $this->erreurEtMenu($numero, "ℹ️ Ce numéro est déjà membre de *{$cagnotte->titre}* (#{$ref}).");
            }
        }

        // Tontine : vérifier places libres (+1 créateur non compté dans nombre_inscrits)
        if ($cagnotte->type === 'tontine_periodique') {
            if (($cagnotte->nombre_inscrits ?? 0) + 1 >= ($cagnotte->nombre_participants ?? 0)) {
                return $this->erreurEtMenu($numero, "❌ *{$cagnotte->titre}* est complet.\nPlus aucune place disponible.");
            }
        }

        // Utilisateur connu → inscrire directement
        if ($user) {
            $this->inscrireParticipant($user, $cagnotte);
            $prenom = ucfirst(mb_strtolower($user->prenom));
            $type   = $cagnotte->type === 'tontine_periodique' ? 'tontine' : 'cagnotte';

            return <<<TXT
            ✅ *Inscription confirmée !*

            Bienvenue *{$prenom}* ! Vous avez rejoint la {$type} *{$cagnotte->titre}* (#{$ref}).

            TXT . "\n" . $this->afficherMenu($numero);
        }

        // Nouvel utilisateur → demander nom + prénom
        $this->session->set($numero, 'rejoindre.nom_prenom', array_merge($data, [
            'numero_payeur' => $numeroSaisi,
        ]));

        return <<<TXT
        👤 *Nouveau sur Tondo*

        Vous n'avez pas encore de compte. On va en créer un rapidement.

        Entrez votre *nom* puis votre *prénom*, chacun sur une ligne :

        _Exemple :_
        MBOULA
        Jean

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleRejoindreNomPrenom(string $numero, string $texte): string
    {
        $lignes = array_filter(array_map('trim', explode("\n", $texte)));

        if (count($lignes) < 2) {
            return <<<TXT
            ⚠️ Format incorrect.
            Entrez votre *nom* puis votre *prénom*, chacun sur une ligne :

            _Exemple :_
            MBOULA
            Jean

            _Tapez_ *#️⃣* _pour annuler._
            TXT;
        }

        $lignes    = array_values($lignes);
        $nom       = mb_strtoupper(trim($lignes[0]));
        $prenom    = ucfirst(mb_strtolower(trim($lignes[1])));
        $data      = $this->session->data($numero);
        $projectId = $data['project_id'] ?? $this->tondoProjectId();
        $cagnotte  = TondoCagnotte::find($data['cagnotte_id'] ?? null);

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Session expirée. Recommencez.");
        }

        $user = $this->cotisationSvc->creerCompteLight(
            nom: $nom,
            prenom: $prenom,
            numeroE164: $data['numero_payeur'] ?? $numero,
            projectId: $projectId,
        );

        $this->inscrireParticipant($user, $cagnotte);

        $ref  = $data['cagnotte_ref'] ?? $cagnotte->reference;
        $type = $cagnotte->type === 'tontine_periodique' ? 'tontine' : 'cagnotte';

        return <<<TXT
        ✅ *Inscription confirmée !*

        Bienvenue *{$prenom}* ! Vous avez rejoint la {$type} *{$cagnotte->titre}* (#{$ref}).

        TXT . "\n" . $this->afficherMenu($numero);
    }

    // ── 3 — Créer ─────────────────────────────────────────────────────────────

    private function demarrerCreer(string $numero): string
    {
        $this->session->set($numero, 'creer.type', [
            'project_id' => $this->tondoProjectId(),
        ]);

        return <<<TXT
        ✨ *Créer une cagnotte*

        Que souhaitez-vous créer ?

        1️⃣  *Cotisation ouverte* — collecte libre, montant variable
        2️⃣  *Tontine périodique* — rotation, montant fixe par cycle

        _Tapez le numéro de votre choix ou_ *#️⃣* _pour annuler._
        TXT;
    }

    private function routerCreer(string $numero, string $etape, string $texte): string
    {
        return match ($etape) {
            'creer.type'                       => $this->handleCreerType($numero, $texte),
            'creer.cotisation.nom'             => $this->handleCreerCotisationNom($numero, $texte),
            'creer.cotisation.montant_cible'   => $this->handleCreerCotisationMontantCible($numero, $texte),
            'creer.cotisation.date_fin'        => $this->handleCreerCotisationDateFin($numero, $texte),
            'creer.tontine.nom'                => $this->handleCreerTontineNom($numero, $texte),
            'creer.tontine.nb_participants'    => $this->handleCreerTontineNbParticipants($numero, $texte),
            'creer.tontine.montant_cycle'      => $this->handleCreerTontineMontantCycle($numero, $texte),
            'creer.tontine.periodicite'        => $this->handleCreerTontinePeriodicite($numero, $texte),
            'creer.tontine.intervalle'         => $this->handleCreerTontineIntervalle($numero, $texte),
            'creer.tontine.jour'               => $this->handleCreerTontineJour($numero, $texte),
            'creer.tontine.penalite'           => $this->handleCreerTontinePenalite($numero, $texte),
            'creer.tontine.penalite_montant'   => $this->handleCreerTontinePenaliteMontant($numero, $texte),
            'creer.tontine.penalite_frequence' => $this->handleCreerTontinePenaliteFrequence($numero, $texte),
            'creer.numero'                     => $this->handleCreerNumero($numero, $texte),
            'creer.nom_prenom'                 => $this->handleCreerNomPrenom($numero, $texte),
            'creer.date_naissance'             => $this->handleCreerDateNaissance($numero, $texte),
            'creer.numero_retrait'             => $this->handleCreerNumeroRetrait($numero, $texte),
            'creer.recap'                      => $this->handleCreerRecap($numero, $texte),
            default                            => $this->afficherMenu($numero),
        };
    }

    private function handleCreerType(string $numero, string $texte): string
    {
        $data = $this->session->data($numero);

        if ($texte === '1') {
            $this->session->set($numero, 'creer.cotisation.nom', array_merge($data, [
                'type' => 'cagnotte_ouverte',
            ]));
            return <<<TXT
            💰 *Cotisation ouverte*

            Quel est le *nom* de votre cagnotte ?
            _(max 120 caractères)_

            _Tapez_ *#️⃣* _pour annuler._
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

            _Tapez_ *#️⃣* _pour annuler._
            TXT;
        }

        return "⚠️ Tapez *1* pour Cotisation ou *2* pour Tontine.\n\n_Tapez_ *#️⃣* _pour annuler._";
    }

    // ── 3.1 Cotisation — champs ───────────────────────────────────────────────

    private function handleCreerCotisationNom(string $numero, string $texte): string
    {
        $titre = trim($texte);
        if (mb_strlen($titre) < 3 || mb_strlen($titre) > 120) {
            return "⚠️ Nom invalide (3 à 120 caractères).\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'creer.cotisation.montant_cible', array_merge($data, [
            'titre' => $titre,
        ]));

        return <<<TXT
        Montant *cible* de la cagnotte ?
        _(objectif de collecte en FCFA — tapez *0* si pas de limite)_

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleCreerCotisationMontantCible(string $numero, string $texte): string
    {
        $montant = (int) preg_replace('/\D/', '', $texte);

        if ($montant !== 0 && ($montant < 100 || $montant > 2_500_000)) {
            return "⚠️ Montant invalide. Entre *100* et *2 500 000 FCFA*, ou *0* pour pas de limite.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'creer.cotisation.date_fin', array_merge($data, [
            'montant_cible' => $montant,
        ]));

        return <<<TXT
        Date *limite* de la cagnotte ?
        _(format : *JJ/MM/AAAA* — tapez *0* pour pas de date limite)_

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleCreerCotisationDateFin(string $numero, string $texte): string
    {
        $data = $this->session->data($numero);

        if (trim($texte) === '0') {
            $this->session->set($numero, 'creer.numero', array_merge($data, ['date_fin' => null]));
            return $this->demanderNumeroCreateur();
        }

        $dt = $this->parseDate(trim($texte));
        if (! $dt) {
            return "⚠️ Format invalide. Utilisez *JJ/MM/AAAA* ou tapez *0* pour aucune limite.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }
        if ($dt <= new \DateTimeImmutable('today')) {
            return "⚠️ La date limite doit être *après aujourd'hui*.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $this->session->set($numero, 'creer.numero', array_merge($data, [
            'date_fin' => $dt->format('Y-m-d'),
        ]));
        return $this->demanderNumeroCreateur();
    }

    // ── 3.1 Tontine — champs ─────────────────────────────────────────────────

    private function handleCreerTontineNom(string $numero, string $texte): string
    {
        $titre = trim($texte);
        if (mb_strlen($titre) < 3 || mb_strlen($titre) > 120) {
            return "⚠️ Nom invalide (3 à 120 caractères).\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'creer.tontine.nb_participants', array_merge($data, [
            'titre' => $titre,
        ]));

        return <<<TXT
        Nombre de *participants* ?
        _(entre 2 et 200)_

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleCreerTontineNbParticipants(string $numero, string $texte): string
    {
        $nb = (int) preg_replace('/\D/', '', $texte);
        if ($nb < 2 || $nb > 200) {
            return "⚠️ Nombre invalide. Entre *2* et *200* participants.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'creer.tontine.montant_cycle', array_merge($data, [
            'nombre_participants' => $nb,
        ]));

        return <<<TXT
        Montant reversé *par cycle* au bénéficiaire ? (en FCFA)
        _💡 Pensez à intégrer vos frais de retrait dans ce montant._

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleCreerTontineMontantCycle(string $numero, string $texte): string
    {
        $montant = (int) preg_replace('/\D/', '', $texte);
        if ($montant < 100 || $montant > 2_500_000) {
            return "⚠️ Montant invalide. Entre *100* et *2 500 000 FCFA*.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'creer.tontine.periodicite', array_merge($data, [
            'montant_par_cycle' => $montant,
        ]));

        return <<<TXT
        *Périodicité* de la tontine ?

        1️⃣  Hebdomadaire
        2️⃣  Mensuelle

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleCreerTontinePeriodicite(string $numero, string $texte): string
    {
        if (! in_array($texte, ['1', '2'])) {
            return "⚠️ Tapez *1* pour Hebdomadaire ou *2* pour Mensuelle.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $periodicite = $texte === '1' ? 'hebdomadaire' : 'mensuelle';
        $data        = $this->session->data($numero);
        $this->session->set($numero, 'creer.tontine.intervalle', array_merge($data, [
            'periodicite' => $periodicite,
        ]));

        $unite = $periodicite === 'hebdomadaire' ? 'semaines' : 'mois';

        return <<<TXT
        Fréquence ?
        _(toutes les X {$unite} — tapez *1* pour chaque {$unite})_

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleCreerTontineIntervalle(string $numero, string $texte): string
    {
        $intervalle = (int) preg_replace('/\D/', '', $texte);
        if ($intervalle < 1 || $intervalle > 12) {
            return "⚠️ Valeur invalide. Entre *1* et *12*.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $data        = $this->session->data($numero);
        $periodicite = $data['periodicite'] ?? '';
        $this->session->set($numero, 'creer.tontine.jour', array_merge($data, [
            'intervalle' => $intervalle,
        ]));

        if ($periodicite === 'hebdomadaire') {
            return <<<TXT
            Jour de *retrait* ?

            1️⃣ Lundi · 2️⃣ Mardi · 3️⃣ Mercredi · 4️⃣ Jeudi
            5️⃣ Vendredi · 6️⃣ Samedi · 7️⃣ Dimanche

            _Tapez_ *#️⃣* _pour annuler._
            TXT;
        }

        return <<<TXT
        Jour du *mois* de retrait ?
        _(entre 1 et 28)_

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleCreerTontineJour(string $numero, string $texte): string
    {
        $data        = $this->session->data($numero);
        $periodicite = $data['periodicite'] ?? '';
        $val         = trim($texte);

        if ($periodicite === 'hebdomadaire') {
            $jours = ['1' => 'lundi', '2' => 'mardi', '3' => 'mercredi', '4' => 'jeudi',
                      '5' => 'vendredi', '6' => 'samedi', '7' => 'dimanche'];
            if (! isset($jours[$val])) {
                return "⚠️ Tapez un chiffre entre *1* (Lundi) et *7* (Dimanche).\n\n_Tapez_ *#️⃣* _pour annuler._";
            }
            $jour = $jours[$val];
        } else {
            $jour = (int) preg_replace('/\D/', '', $val);
            if ($jour < 1 || $jour > 28) {
                return "⚠️ Jour invalide. Entre *1* et *28*.\n\n_Tapez_ *#️⃣* _pour annuler._";
            }
        }

        $this->session->set($numero, 'creer.tontine.penalite', array_merge($data, [
            'jour' => $jour,
        ]));

        return <<<TXT
        *Pénalité* de retard pour les cotisants en retard ?

        1️⃣  Oui
        0️⃣  Non

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleCreerTontinePenalite(string $numero, string $texte): string
    {
        $data = $this->session->data($numero);

        if ($texte === '0') {
            $this->session->set($numero, 'creer.numero', array_merge($data, [
                'penalite_active' => false,
            ]));
            return $this->demanderNumeroCreateur();
        }

        if ($texte === '1') {
            $this->session->set($numero, 'creer.tontine.penalite_montant', array_merge($data, [
                'penalite_active' => true,
            ]));
            return <<<TXT
            Montant de la pénalité ? (en FCFA)
            _(entre 100 et 500 000 FCFA)_

            _Tapez_ *#️⃣* _pour annuler._
            TXT;
        }

        return "⚠️ Tapez *1* pour Oui ou *0* pour Non.\n\n_Tapez_ *#️⃣* _pour annuler._";
    }

    private function handleCreerTontinePenaliteMontant(string $numero, string $texte): string
    {
        $montant = (int) preg_replace('/\D/', '', $texte);
        if ($montant < 100 || $montant > 500_000) {
            return "⚠️ Montant invalide. Entre *100* et *500 000 FCFA*.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'creer.tontine.penalite_frequence', array_merge($data, [
            'penalite_montant' => $montant,
        ]));

        return <<<TXT
        Fréquence de la pénalité ?

        1️⃣  Par *heure* de retard
        2️⃣  Par *jour* de retard

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleCreerTontinePenaliteFrequence(string $numero, string $texte): string
    {
        if (! in_array($texte, ['1', '2'])) {
            return "⚠️ Tapez *1* pour Par heure ou *2* pour Par jour.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $frequence = $texte === '1' ? 'heure' : 'jour';
        $data      = $this->session->data($numero);
        $this->session->set($numero, 'creer.numero', array_merge($data, [
            'penalite_frequence' => $frequence,
        ]));

        return $this->demanderNumeroCreateur();
    }

    // ── 3.2 Identification du créateur (partagé cotisation + tontine) ─────────

    private function demanderNumeroCreateur(): string
    {
        return <<<TXT
        📱 Votre *numéro Mobile Money* ?
        _(format : *0XXXXXXXX*)_

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleCreerNumero(string $numero, string $texte): string
    {
        $numeroSaisi = $this->normaliserNumero($texte);
        if (! $numeroSaisi) {
            return "⚠️ Numéro invalide. Format : *0XXXXXXXX*\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $data      = $this->session->data($numero);
        $projectId = $data['project_id'] ?? $this->tondoProjectId();
        $user      = $this->utilisateurParNumero($numeroSaisi, $projectId);

        if ($user) {
            $this->session->set($numero, 'creer.numero_retrait', array_merge($data, [
                'user_id'       => $user->id,
                'numero_payeur' => $numeroSaisi,
            ]));
            return $this->demanderNumeroRetrait($user->prenom);
        }

        $this->session->set($numero, 'creer.nom_prenom', array_merge($data, [
            'numero_payeur' => $numeroSaisi,
        ]));

        return <<<TXT
        👤 *Nouveau sur Tondo*

        Vous n'avez pas encore de compte. On va en créer un.

        Entrez votre *nom* puis votre *prénom*, chacun sur une ligne :

        _Exemple :_
        MBOULA
        Jean

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleCreerNomPrenom(string $numero, string $texte): string
    {
        $lignes = array_values(array_filter(array_map('trim', explode("\n", $texte))));

        if (count($lignes) < 2) {
            return <<<TXT
            ⚠️ Format incorrect. Entrez *nom* puis *prénom*, chacun sur une ligne.

            _Tapez_ *#️⃣* _pour annuler._
            TXT;
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'creer.date_naissance', array_merge($data, [
            'nom'    => mb_strtoupper(trim($lignes[0])),
            'prenom' => ucfirst(mb_strtolower(trim($lignes[1]))),
        ]));

        return <<<TXT
        📅 Date de *naissance* ?
        _(format : *JJ/MM/AAAA* — vous devez avoir au moins 18 ans)_

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleCreerDateNaissance(string $numero, string $texte): string
    {
        $dt = $this->parseDate(trim($texte));
        if (! $dt) {
            return "⚠️ Format invalide. Utilisez *JJ/MM/AAAA*.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }
        if (! $this->estMajeur($dt)) {
            return $this->erreurEtMenu($numero, "❌ Vous devez avoir *18 ans ou plus* pour créer une cagnotte.");
        }

        $data      = $this->session->data($numero);
        $projectId = $data['project_id'] ?? $this->tondoProjectId();

        $user = $this->cotisationSvc->creerCompteFull(
            nom:           $data['nom'],
            prenom:        $data['prenom'],
            numeroE164:    $data['numero_payeur'],
            projectId:     $projectId,
            dateNaissance: $dt->format('Y-m-d'),
        );

        $this->session->set($numero, 'creer.numero_retrait', array_merge($data, [
            'user_id' => $user->id,
        ]));

        return $this->demanderNumeroRetrait($user->prenom);
    }

    private function demanderNumeroRetrait(string $prenom): string
    {
        return <<<TXT
        Bonjour *{$prenom}* !

        Le montant collecté sera reversé sur votre numéro Mobile Money.
        Voulez-vous utiliser un *autre numéro* pour le retrait ?

        _(tapez le numéro alternatif au format *0XXXXXXXX*, ou *0* pour utiliser le même)_

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleCreerNumeroRetrait(string $numero, string $texte): string
    {
        $data = $this->session->data($numero);

        if (trim($texte) === '0') {
            $numeroRetrait = $data['numero_payeur'];
        } else {
            $numeroRetrait = $this->normaliserNumero($texte);
            if (! $numeroRetrait) {
                return "⚠️ Numéro invalide. Format : *0XXXXXXXX* ou tapez *0* pour le même numéro.\n\n_Tapez_ *#️⃣* _pour annuler._";
            }
        }

        $this->session->set($numero, 'creer.recap', array_merge($data, [
            'numero_retrait' => $numeroRetrait,
        ]));

        return $this->construireRecap($data, $numeroRetrait);
    }

    // ── 3.3 Récap + CGU ──────────────────────────────────────────────────────

    private function construireRecap(array $data, string $numeroRetrait): string
    {
        $masque = $this->maskPhoneNum($numeroRetrait);

        if ($data['type'] === 'tontine_periodique') {
            $montant     = number_format((int) $data['montant_par_cycle'], 0, ',', ' ');
            $periodicite = $data['periodicite'] === 'hebdomadaire' ? 'Hebdomadaire' : 'Mensuelle';
            $unite       = $data['periodicite'] === 'hebdomadaire' ? 'semaine(s)' : 'mois';
            $intervalle  = (int) ($data['intervalle'] ?? 1);
            $jour        = is_string($data['jour'] ?? null) ? ucfirst($data['jour']) : 'Jour ' . ($data['jour'] ?? '?') . ' du mois';
            $penalite    = ($data['penalite_active'] ?? false)
                ? number_format((int) ($data['penalite_montant'] ?? 0), 0, ',', ' ') . ' FCFA/' . ($data['penalite_frequence'] ?? 'jour')
                : 'Non';

            $lignes = <<<TXT
            📝 *Récapitulatif — Tontine périodique*

            Nom : *{$data['titre']}*
            Participants : *{$data['nombre_participants']}*
            Montant/cycle : *{$montant} FCFA*
            Périodicité : *{$periodicite}* · toutes les {$intervalle} {$unite}
            Jour de retrait : *{$jour}*
            Pénalité de retard : *{$penalite}*
            Numéro de retrait : *{$masque}*
            TXT;
        } else {
            $cible    = isset($data['montant_cible']) && (int) $data['montant_cible'] > 0
                ? number_format((int) $data['montant_cible'], 0, ',', ' ') . ' FCFA'
                : 'Pas de limite';
            $dateFin  = $data['date_fin'] ?? null;
            $dateFin  = $dateFin ? (new \DateTimeImmutable($dateFin))->format('d/m/Y') : 'Pas de limite';

            $lignes = <<<TXT
            📝 *Récapitulatif — Cotisation ouverte*

            Nom : *{$data['titre']}*
            Montant cible : *{$cible}*
            Date limite : *{$dateFin}*
            Numéro de retrait : *{$masque}*
            TXT;
        }

        return $lignes . "\n\n" . $this->cguTexte();
    }

    private function cguTexte(): string
    {
        return <<<TXT
        ─────────────────
        📋 *Conditions d'utilisation*

        • Le montant collecté est reversé sur votre numéro de retrait.
        • Le numéro de retrait *ne peut plus être modifié* après création.
        • Les frais sont à la charge du cotisant, appliqués au paiement.
        • Tondo n'arbitre pas les conflits entre membres.

        _Détail_
        *Modèle économique* — Commission Tondo 2 %. Frais opérateur : 3 % au paiement (plafond 5 000 FCFA) + 3 % au retrait. Le bénéficiaire reçoit le montant net.
        *Numéro de retrait* — Immuable après création. Protège les participants contre la fraude.
        *Différends* — Tondo facilite la collecte mais n'arbitre pas les conflits, sauf cas manifestement clair (ex : usurpation d'identité).
        *Périmètre v1* — Cagnottes publiques et associations comme bénéficiaires non disponibles (loi gabonaise n°35/62).
        ─────────────────

        Tapez *1* pour confirmer et créer · *0* pour annuler.
        TXT;
    }

    // ── 3.4 Création effective ────────────────────────────────────────────────

    private function handleCreerRecap(string $numero, string $texte): string
    {
        if ($texte === '0') {
            return $this->erreurEtMenu($numero, "🚫 Création annulée.");
        }

        if ($texte !== '1') {
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
            return $this->erreurEtMenu($numero, "❌ Erreur lors de la création. Réessayez ou contactez support@tondo.ga.");
        }

        $type   = $cagnotte->type === 'tontine_periodique' ? 'tontine' : 'cagnotte';
        $prenom = ucfirst(mb_strtolower($user->prenom));
        $ref    = $cagnotte->reference;

        return <<<TXT
        🎉 *{$cagnotte->titre}* créée avec succès !

        Félicitations *{$prenom}* !
        Votre {$type} est active.

        *Code : #{$ref}*
        Partagez ce code avec vos participants.

        TXT . "\n" . $this->afficherMenu($numero);
    }

    // ── 3 — Helpers ──────────────────────────────────────────────────────────

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

    private function estMajeur(\DateTimeImmutable $naissance): bool
    {
        return $naissance->diff(new \DateTimeImmutable('today'))->y >= 18;
    }

    // ── 4 — Gérer ─────────────────────────────────────────────────────────────

    private function demarrerGerer(string $numero): string
    {
        $this->session->set($numero, 'gerer.numero', [
            'project_id' => $this->tondoProjectId(),
        ]);

        return <<<TXT
        📋 *Gérer mes cagnottes*

        Votre *numéro Mobile Money* ?
        _(format : *0XXXXXXXX*)_

        _Tapez_ *#️⃣* _pour revenir au menu._
        TXT;
    }

    private function routerGerer(string $numero, string $etape, string $texte): string
    {
        return match ($etape) {
            'gerer.numero'           => $this->handleGererNumero($numero, $texte),
            'gerer.otp'              => $this->handleGererOtp($numero, $texte),
            'gerer.nom_prenom'       => $this->handleGererNomPrenom($numero, $texte),
            'gerer.date_naissance'   => $this->handleGererDateNaissance($numero, $texte),
            'gerer.liste'            => $this->handleGererListe($numero, $texte),
            'gerer.cagnotte'         => $this->handleGererCagnotte($numero, $texte),
            'gerer.historique'       => $this->handleGererHistorique($numero, $texte),
            'gerer.revers.dest'      => $this->handleGererReversementDest($numero, $texte),
            'gerer.revers.num'       => $this->handleGererReversementNum($numero, $texte),
            'gerer.revers.mont'      => $this->handleGererReversementMontant($numero, $texte),
            'gerer.revers.otp'       => $this->handleGererReversementOtp($numero, $texte),
            'gerer.tontine'          => $this->handleGererTontine($numero, $texte),
            'gerer.tontine.ordre'    => $this->handleGererTontineOrdre($numero, $texte),
            'gerer.tontine.hist'     => $this->handleGererTontineHistorique($numero, $texte),
            default                  => $this->afficherMenu($numero),
        };
    }

    private function handleGererNumero(string $numero, string $texte): string
    {
        $numeroSaisi = $this->normaliserNumero($texte);
        if (! $numeroSaisi) {
            return "⚠️ Numéro invalide. Format : *0XXXXXXXX*\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $data = $this->session->data($numero);

        try {
            $this->twilioVerify->sendOtp($numeroSaisi);
        } catch (\Throwable $e) {
            Log::warning('handleGererNumero: échec envoi OTP', [
                'numero' => $numeroSaisi,
                'err'    => $e->getMessage(),
            ]);
            return "⚠️ Impossible d'envoyer le code de vérification sur *{$this->maskPhoneNum($numeroSaisi)}*.\nVérifiez le numéro ou réessayez.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $this->session->set($numero, 'gerer.otp', array_merge($data, [
            'numero_payeur' => $numeroSaisi,
        ]));

        $masque = $this->maskPhoneNum($numeroSaisi);

        return <<<TXT
        🔐 *Vérification de votre identité*

        Un code à 6 chiffres a été envoyé par SMS au *{$masque}*.
        Entrez ce code pour continuer :

        _(Code valable 10 minutes)_

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleGererOtp(string $numero, string $texte): string
    {
        $data          = $this->session->data($numero);
        $numeroSaisi   = $data['numero_payeur'] ?? '';
        $code          = trim($texte);

        if (! preg_match('/^\d{6}$/', $code)) {
            return "⚠️ Entrez le code à *6 chiffres* reçu par SMS.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        try {
            $approuve = $this->twilioVerify->checkOtp($numeroSaisi, $code);
        } catch (\Throwable $e) {
            Log::error('handleGererOtp: erreur checkOtp', ['err' => $e->getMessage()]);
            return $this->erreurEtMenu($numero, "❌ Erreur technique lors de la vérification. Réessayez.");
        }

        if (! $approuve) {
            return "❌ Code incorrect ou expiré.\nRessayez ou tapez *#️⃣* pour annuler.";
        }

        // OTP validé — continuer le flow normal
        $projectId = $data['project_id'] ?? $this->tondoProjectId();
        $user      = $this->utilisateurParNumero($numeroSaisi, $projectId);

        if ($user) {
            return $this->afficherListeCagnottes($numero, $user, array_merge($data, [
                'user_id' => $user->id,
            ]));
        }

        $this->session->set($numero, 'gerer.nom_prenom', $data);

        return <<<TXT
        ✅ *Identité vérifiée !*

        Vous n'avez pas encore de compte Tondo. On va en créer un.

        Entrez votre *nom* puis votre *prénom*, chacun sur une ligne :

        _Exemple :_
        MBOULA
        Jean

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleGererNomPrenom(string $numero, string $texte): string
    {
        $lignes = array_values(array_filter(array_map('trim', explode("\n", $texte))));

        if (count($lignes) < 2) {
            return "⚠️ Format incorrect. Entrez *nom* puis *prénom*, chacun sur une ligne.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'gerer.date_naissance', array_merge($data, [
            'nom'    => mb_strtoupper(trim($lignes[0])),
            'prenom' => ucfirst(mb_strtolower(trim($lignes[1]))),
        ]));

        return "📅 Date de *naissance* ?\n_(format : *JJ/MM/AAAA*)_\n\n_Tapez_ *#️⃣* _pour annuler._";
    }

    private function handleGererDateNaissance(string $numero, string $texte): string
    {
        $dt = $this->parseDate(trim($texte));
        if (! $dt) {
            return "⚠️ Format invalide. Utilisez *JJ/MM/AAAA*.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }
        if (! $this->estMajeur($dt)) {
            return $this->erreurEtMenu($numero, "❌ Vous devez avoir *18 ans ou plus*.");
        }

        $data      = $this->session->data($numero);
        $projectId = $data['project_id'] ?? $this->tondoProjectId();

        $user = $this->cotisationSvc->creerCompteFull(
            nom:           $data['nom'],
            prenom:        $data['prenom'],
            numeroE164:    $data['numero_payeur'],
            projectId:     $projectId,
            dateNaissance: $dt->format('Y-m-d'),
        );

        return $this->afficherListeCagnottes($numero, $user, array_merge($data, [
            'user_id' => $user->id,
        ]));
    }

    private function afficherListeCagnottes(string $numero, TondoUser $user, array $data): string
    {
        $cagnottes = $this->gererCagnotteSvc->cagnottesGerees($user);

        if ($cagnottes->isEmpty()) {
            return $this->erreurEtMenu($numero,
                "📭 Vous n'avez aucune cagnotte active.\nTapez *3* pour en créer une."
            );
        }

        $liste    = $cagnottes->values();
        $index    = $liste->map(fn ($c, $i) => ($i + 1) . ". *{$c->titre}* · #{$c->reference} · "
            . ($c->type === 'tontine_periodique' ? 'Tontine' : 'Cotisation')
        )->implode("\n");

        $refs = $liste->pluck('reference')->toArray();

        $this->session->set($numero, 'gerer.liste', array_merge($data, ['refs' => $refs]));

        return <<<TXT
        📋 *Vos cagnottes actives*

        {$index}

        Quelle cagnotte souhaitez-vous gérer ?
        _(tapez le numéro correspondant)_

        _Tapez_ *#️⃣* _pour revenir au menu._
        TXT;
    }

    private function handleGererListe(string $numero, string $texte): string
    {
        $data  = $this->session->data($numero);
        $refs  = $data['refs'] ?? [];
        $choix = (int) trim($texte);

        if ($choix < 1 || $choix > count($refs)) {
            return "⚠️ Tapez un chiffre entre *1* et *" . count($refs) . "*.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $ref      = $refs[$choix - 1];
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
        💼 *{$cagnotte->titre}* · #{$ref}
        Solde disponible : *{$collecte} FCFA*

        Que souhaitez-vous faire ?

        1️⃣  *Historique* des transactions
        2️⃣  *Initier* un reversement
        3️⃣  Retour à la liste

        _Tapez_ *#️⃣* _pour revenir au menu principal._
        TXT;
    }

    private function handleGererCagnotte(string $numero, string $texte): string
    {
        $data     = $this->session->data($numero);
        $cagnotte = TondoCagnotte::find($data['cagnotte_id'] ?? null);

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Session expirée. Recommencez.");
        }

        if ($texte === '3') {
            return $this->retourListeCagnottes($numero, $data);
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
                3️⃣  Retour à la liste
                TXT;
            }

            $total  = number_format((int) $paiements->sum('montant'), 0, ',', ' ');
            $lignes = $paiements->map(fn ($p) =>
                \Carbon\Carbon::parse($p->updated_at)->format('d/m') .
                ' · ' . $p->cotisant .
                ' · *' . number_format((int) $p->montant, 0, ',', ' ') . ' FCFA*'
            )->implode("\n");

            $nb = $paiements->count();

            $this->session->set($numero, 'gerer.historique', $data);

            return <<<TXT
            📊 *Historique — {$cagnotte->titre}*
            Total collecté : *{$total} FCFA* · {$nb} transaction(s)

            {$lignes}

            ————————————————
            Exporter en *PDF* ?

            1️⃣  Oui — recevoir le lien
            0️⃣  Non — retour menu
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

            _Tapez_ *#️⃣* _pour annuler._
            TXT;
        }

        return "⚠️ Tapez *1*, *2* ou *3*.\n\n_Tapez_ *#️⃣* _pour revenir au menu principal._";
    }

    // ── 4 — Gérer > Tontine ───────────────────────────────────────────────────

    private function afficherMenuTontine(string $numero, TondoCagnotte $cagnotte, array $data): string
    {
        // +1 car le créateur est dans tondo_participants mais pas compté dans nombre_inscrits
        $inscrits = (int) $cagnotte->nombre_inscrits + 1;
        $max      = (int) $cagnotte->nombre_participants;
        $lancee   = ! is_null($cagnotte->date_debut) || (int) $cagnotte->montant_collecte > 0;

        $etat = $lancee ? 'demarree' : ($inscrits >= $max ? 'pleine' : 'attente');

        $this->session->set($numero, 'gerer.tontine', array_merge($data, [
            'tontine_etat' => $etat,
        ]));

        $titre = $cagnotte->titre;
        $ref   = $cagnotte->reference;

        if ($lancee) {
            return <<<TXT
            🔄 *{$titre}* · #{$ref}
            Participants : {$inscrits}/{$max} · Tontine en cours

            Que souhaitez-vous faire ?

            1️⃣  Historique des transactions
            2️⃣  Retour au menu précédant
            3️⃣  Menu principal

            _Tapez le numéro de votre choix._
            TXT;
        }

        if ($inscrits >= $max) {
            return <<<TXT
            ⏳ *{$titre}* · #{$ref}
            Participants : {$inscrits}/{$max} ✅ Complet — Prête à démarrer !

            Que souhaitez-vous faire ?

            1️⃣  Démarrer la tontine
            2️⃣  Éditer l'ordre des participants
            3️⃣  Supprimer la tontine
            4️⃣  Retour au menu précédant
            5️⃣  Menu principal

            _Tapez le numéro de votre choix._
            TXT;
        }

        $manquants = $max - $inscrits;
        return <<<TXT
        ⏳ *{$titre}* · #{$ref}
        Participants : {$inscrits}/{$max} _(il manque {$manquants})_

        Que souhaitez-vous faire ?

        1️⃣  Supprimer la tontine
        2️⃣  Retour au menu précédant
        3️⃣  Menu principal

        _Tapez le numéro de votre choix._
        TXT;
    }

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
                default => "⚠️ Tapez *1*, *2* ou *3*.\n\n_Tapez_ *#️⃣* _pour annuler._",
            };
        }

        if ($etat === 'pleine') {
            return match ($choix) {
                '1' => $this->executerDemarrerTontine($numero, $cagnotte),
                '2' => $this->demarrerEditionOrdre($numero, $cagnotte, $data),
                '3' => $this->executerSupprimerTontine($numero, $cagnotte),
                '4' => $this->retourListeCagnottes($numero, $data),
                '5' => $this->afficherMenu($numero),
                default => "⚠️ Tapez un chiffre de *1* à *5*.\n\n_Tapez_ *#️⃣* _pour annuler._",
            };
        }

        // etat 'attente' (pas pleine)
        return match ($choix) {
            '1' => $this->executerSupprimerTontine($numero, $cagnotte),
            '2' => $this->retourListeCagnottes($numero, $data),
            '3' => $this->afficherMenu($numero),
            default => "⚠️ Tapez *1*, *2* ou *3*.\n\n_Tapez_ *#️⃣* _pour annuler._",
        };
    }

    private function executerDemarrerTontine(string $numero, TondoCagnotte $cagnotte): string
    {
        DB::table('tondo_cagnottes')->where('id', $cagnotte->id)->update([
            'date_debut' => now(),
            'updated_at' => now(),
        ]);

        return <<<TXT
        🎉 *Tontine lancée !*

        La tontine *{$cagnotte->titre}* est maintenant active.

        TXT . "\n" . $this->afficherMenu($numero);
    }

    private function executerSupprimerTontine(string $numero, TondoCagnotte $cagnotte): string
    {
        DB::table('tondo_cagnottes')->where('id', $cagnotte->id)->update([
            'statut'     => 'annulee',
            'updated_at' => now(),
        ]);

        return <<<TXT
        ✅ *Tontine supprimée.*

        La tontine *{$cagnotte->titre}* a bien été supprimée.

        TXT . "\n" . $this->afficherMenu($numero);
    }

    private function demarrerEditionOrdre(string $numero, TondoCagnotte $cagnotte, array $data): string
    {
        $participants = DB::table('tondo_participants')
            ->where('cagnotte_id', $cagnotte->id)
            ->orderByRaw('COALESCE(ordre, 9999)')
            ->orderBy('created_at')
            ->select(['id', 'nom', 'prenom'])
            ->get();

        $ids   = $participants->pluck('id')->toArray();
        $liste = $participants->values()->map(fn ($p, $i) =>
            ($i + 1) . '. ' . mb_strtoupper($p->nom) . ' ' . ucfirst(mb_strtolower($p->prenom))
        )->implode("\n");

        $n = count($ids);

        $this->session->set($numero, 'gerer.tontine.ordre', array_merge($data, [
            'participant_ids' => $ids,
        ]));

        return <<<TXT
        📋 *Ordre actuel des participants*

        {$liste}

        Envoyez les paires *position actuelle - nouvelle position* (une par ligne) :
        _Exemple :_
        5-1
        2-3
        4-2
        1-4
        3-5

        _(Toutes les {$n} positions doivent être couvertes.)_

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleGererTontineOrdre(string $numero, string $texte): string
    {
        $data = $this->session->data($numero);
        $ids  = $data['participant_ids'] ?? [];
        $n    = count($ids);

        $lignes = array_values(array_filter(array_map('trim', explode("\n", $texte))));
        $pairs  = [];

        foreach ($lignes as $ligne) {
            if (! preg_match('/^(\d+)-(\d+)$/', $ligne, $m)) {
                return "⚠️ Format invalide : *{$ligne}*\nUtilisez *X-Y* (ex: `3-1`).\n\n_Tapez_ *#️⃣* _pour annuler._";
            }
            $ancien  = (int) $m[1];
            $nouveau = (int) $m[2];
            if ($ancien < 1 || $ancien > $n || $nouveau < 1 || $nouveau > $n) {
                return "⚠️ Position hors plage dans *{$ligne}* (1 à {$n}).\n\n_Tapez_ *#️⃣* _pour annuler._";
            }
            $pairs[$ancien] = $nouveau;
        }

        if (count($pairs) !== $n) {
            return "⚠️ Envoyez exactement *{$n}* paires (une par participant).\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        // Vérifier que chaque position source et destination est unique
        $sources = array_keys($pairs);
        $dests   = array_values($pairs);
        if (count(array_unique($sources)) !== $n || count(array_unique($dests)) !== $n) {
            return "⚠️ Chaque position doit apparaître exactement *une fois* en source et en destination.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        foreach ($pairs as $ancien => $nouveau) {
            $id = $ids[$ancien - 1] ?? null;
            if ($id) {
                DB::table('tondo_participants')
                    ->where('id', $id)
                    ->update(['ordre' => $nouveau, 'updated_at' => now()]);
            }
        }

        $cagnotte = TondoCagnotte::find($data['cagnotte_id'] ?? null);

        return "✅ *Ordre mis à jour !*\n\n" . $this->afficherMenuTontine($numero, $cagnotte, $data);
    }

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

        $total  = number_format((int) $paiements->sum('montant'), 0, ',', ' ');
        $lignes = $paiements->map(fn ($p) =>
            \Carbon\Carbon::parse($p->updated_at)->format('d/m') .
            ' · ' . $p->cotisant .
            ' · *' . number_format((int) $p->montant, 0, ',', ' ') . ' FCFA*'
        )->implode("\n");

        $nb = $paiements->count();

        $this->session->set($numero, 'gerer.tontine.hist', $data);

        return <<<TXT
        📊 *Historique — {$cagnotte->titre}*
        Total : *{$total} FCFA* · {$nb} transaction(s)

        {$lignes}

        ————————————————
        1️⃣  Exporter en PDF _(lien valable 24h)_
        2️⃣  Retour au menu précédant
        3️⃣  Menu principal
        TXT;
    }

    private function handleGererTontineHistorique(string $numero, string $texte): string
    {
        $data     = $this->session->data($numero);
        $cagnotte = TondoCagnotte::find($data['cagnotte_id'] ?? null);

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Session expirée. Recommencez.");
        }

        if ($texte === '1') {
            try {
                $pdfUrl = $this->gererCagnotteSvc->genererHistoriquePdf($cagnotte);
                return <<<TXT
                📄 *Historique PDF*

                {$pdfUrl}

                _(Ce lien expire dans 24h.)_

                TXT . "\n" . $this->afficherMenu($numero);
            } catch (\Throwable $e) {
                Log::error('handleGererTontineHistorique: échec PDF', ['err' => $e->getMessage()]);
                return $this->erreurEtMenu($numero, "❌ Impossible de générer le PDF. Réessayez plus tard.");
            }
        }

        if ($texte === '2') {
            return $this->retourListeCagnottes($numero, $data);
        }

        if ($texte === '3') {
            return $this->afficherMenu($numero);
        }

        return "⚠️ Tapez *1*, *2* ou *3*.\n\n_Tapez_ *#️⃣* _pour annuler._";
    }

    private function retourListeCagnottes(string $numero, array $data): string
    {
        $user = TondoUser::find($data['user_id'] ?? null);
        if (! $user) {
            return $this->afficherMenu($numero);
        }
        return $this->afficherListeCagnottes($numero, $user, $data);
    }

    private function handleGererHistorique(string $numero, string $texte): string
    {
        $data     = $this->session->data($numero);
        $cagnotte = TondoCagnotte::find($data['cagnotte_id'] ?? null);

        if ($texte === '0') {
            // Retour au menu cagnotte
            $this->session->set($numero, 'gerer.cagnotte', $data);
            $collecte = number_format((int) ($cagnotte?->montant_collecte ?? 0), 0, ',', ' ');
            $titre    = $cagnotte?->titre ?? '—';
            $ref      = $data['cagnotte_ref'] ?? '—';

            return <<<TXT
            💼 *{$titre}* · #{$ref}
            Solde disponible : *{$collecte} FCFA*

            1️⃣  *Historique* des transactions
            2️⃣  *Initier* un reversement
            3️⃣  Retour à la liste

            _Tapez_ *#️⃣* _pour revenir au menu principal._
            TXT;
        }

        if ($texte === '1') {
            if (! $cagnotte) {
                return $this->erreurEtMenu($numero, "❌ Session expirée. Recommencez.");
            }

            try {
                $pdfUrl = $this->gererCagnotteSvc->genererHistoriquePdf($cagnotte);
                $this->session->reset($numero);
                return <<<TXT
                📄 *Historique PDF*

                {$pdfUrl}

                _(Ce lien expire dans 24h.)_

                TXT . "\n" . $this->afficherMenu($numero);
            } catch (\Throwable $e) {
                Log::error('handleGererHistorique: échec PDF', ['err' => $e->getMessage()]);
                return $this->erreurEtMenu($numero, "❌ Impossible de générer le PDF. Réessayez plus tard.");
            }
        }

        return "⚠️ Tapez *1* pour le PDF ou *0* pour revenir.\n\n_Tapez_ *#️⃣* _pour annuler._";
    }

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
            return "Entrez le *numéro* Mobile Money du bénéficiaire :\n_(format : *0XXXXXXXX*)_\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        return "⚠️ Tapez *1* pour Mon numéro ou *2* pour Autre numéro.\n\n_Tapez_ *#️⃣* _pour annuler._";
    }

    private function handleGererReversementNum(string $numero, string $texte): string
    {
        $numeroSaisi = $this->normaliserNumero($texte);
        if (! $numeroSaisi) {
            return "⚠️ Numéro invalide. Format : *0XXXXXXXX*\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'gerer.revers.mont', array_merge($data, [
            'revers_numero' => $numeroSaisi,
        ]));

        $masque = $this->maskPhoneNum($numeroSaisi);
        return $this->demanderMontantReversement($masque);
    }

    private function demanderMontantReversement(string $masque): string
    {
        return <<<TXT
        Bénéficiaire : *{$masque}*

        Quel *montant* souhaitez-vous reverser ? (en FCFA)
        _(min 100 — ne peut pas dépasser le solde disponible)_

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleGererReversementMontant(string $numero, string $texte): string
    {
        $montant = (int) preg_replace('/\D/', '', $texte);
        if ($montant < 100) {
            return "⚠️ Montant minimum : *100 FCFA*.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        $data     = $this->session->data($numero);
        $cagnotte = TondoCagnotte::find($data['cagnotte_id'] ?? null);

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Session expirée. Recommencez.");
        }

        if ((int) $cagnotte->montant_collecte < $montant) {
            $dispo = number_format((int) $cagnotte->montant_collecte, 0, ',', ' ');
            return "⚠️ Solde insuffisant. Disponible : *{$dispo} FCFA*.\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        // Générer OTP (simulé en dev = 123456)
        $otp = app()->isProduction()
            ? (string) random_int(100000, 999999)
            : '123456';

        $this->session->set($numero, 'gerer.revers.otp', array_merge($data, [
            'revers_montant' => $montant,
            'otp'            => $otp,
            'otp_expires_at' => now()->addMinutes(5)->timestamp,
            'otp_attempts'   => 0,
        ]));

        $masque      = $this->maskPhoneNum($data['revers_numero'] ?? '');
        $montantFmt  = number_format($montant, 0, ',', ' ');
        $gerantNum   = $this->maskPhoneNum($data['numero_payeur'] ?? '');

        // En prod, on enverrait le SMS ; ici le bot lui-même affiche le code (sandbox)
        return <<<TXT
        🔐 *Confirmation requise*

        Reversement de *{$montantFmt} FCFA* vers *{$masque}*

        Un code de confirmation a été envoyé au *{$gerantNum}*.
        Entrez le code à 6 chiffres pour valider :

        _(Code valable 5 minutes — 3 essais max)_

        _Tapez_ *#️⃣* _pour annuler._
        TXT;
    }

    private function handleGererReversementOtp(string $numero, string $texte): string
    {
        $data = $this->session->data($numero);

        $otp        = $data['otp'] ?? '';
        $expiresAt  = (int) ($data['otp_expires_at'] ?? 0);
        $attempts   = (int) ($data['otp_attempts'] ?? 0);

        if (now()->timestamp > $expiresAt) {
            return $this->erreurEtMenu($numero, "⏰ Code expiré. Recommencez le reversement.");
        }

        $attempts++;

        if (trim($texte) !== $otp) {
            if ($attempts >= 3) {
                return $this->erreurEtMenu($numero, "❌ Code incorrect 3 fois. Reversement annulé pour sécurité.");
            }

            $this->session->set($numero, 'gerer.revers.otp', array_merge($data, ['otp_attempts' => $attempts]));
            $restants = 3 - $attempts;
            return "❌ Code incorrect. *{$restants} essai(s) restant(s).*\n\n_Tapez_ *#️⃣* _pour annuler._";
        }

        // OTP valide → exécuter le reversement
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
            return $this->erreurEtMenu($numero, "❌ " . $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('handleGererReversementOtp: erreur inattendue', ['err' => $e->getMessage()]);
            return $this->erreurEtMenu($numero, "❌ Erreur technique. Contactez support@tondo.ga.");
        }

        $montantFmt = number_format($result['montant'], 0, ',', ' ');

        return <<<TXT
        ✅ *Reversement effectué !*

        Montant : *{$montantFmt} FCFA*
        Bénéficiaire : *{$masque}*
        Référence : `{$result['trans_id']}`

        TXT . "\n" . $this->afficherMenu($numero);
    }

    // ── 5 — Aide ──────────────────────────────────────────────────────────────

    private function afficherAide(string $numero): string
    {
        return <<<TXT
        ❓ *Aide & support Tondo*

        Pour toute question, problème ou réclamation, notre équipe est disponible :

        📧 *Email* : support@tondo.ga
        _(Réponse sous 24h, jours ouvrables)_

        ————————————————
        TXT . "\n" . $this->afficherMenu($numero);
    }

    // ── Utilitaires ───────────────────────────────────────────────────────────

    private function estRetourMenu(string $texte): bool
    {
        return in_array(mb_strtolower(trim($texte)), ['#', 'menu', 'retour', 'annuler', 'cancel', 'stop'], true);
    }

    private function utilisateur(string $numero): ?TondoUser
    {
        $suffixe   = substr(preg_replace('/\D/', '', $numero), -9);
        $projectId = $this->tondoProjectId();

        return TondoUser::where('project_id', $projectId)
            ->where('numero', 'like', "%{$suffixe}")
            ->first();
    }

    private function utilisateurParNumero(string $numeroE164, string $projectId): ?TondoUser
    {
        $suffixe = substr(preg_replace('/\D/', '', $numeroE164), -9);

        return TondoUser::where('project_id', $projectId)
            ->where('numero', 'like', "%{$suffixe}")
            ->first();
    }

    private function normaliserNumero(string $texte): ?string
    {
        $texte    = trim($texte);
        $chiffres = preg_replace('/\D/', '', $texte);

        if (strlen($chiffres) < 6) {
            return null;
        }

        // Numéro international avec + → on garde tel quel
        if (str_starts_with($texte, '+')) {
            return '+' . $chiffres;
        }

        // 00XXXXXXXXXXX → traiter comme international
        if (str_starts_with($chiffres, '00')) {
            return '+' . substr($chiffres, 2);
        }

        // Gabon local commençant par 0 : 0XXXXXXXX (9 à 11 chiffres)
        if (str_starts_with($chiffres, '0') && strlen($chiffres) >= 9 && strlen($chiffres) <= 11) {
            return '+241' . substr($chiffres, 1);
        }

        // Gabon sans 0 : 77XXXXXX (8 chiffres)
        if (strlen($chiffres) === 8) {
            return '+241' . $chiffres;
        }

        // Numéro complet sans + (ex: 24177XXXXXX ou 221XXXXXXXXX)
        if (strlen($chiffres) >= 10) {
            return '+' . $chiffres;
        }

        return null;
    }

    private function tondoProjectId(): string
    {
        static $id = null;
        if ($id === null) {
            $id = DB::table('projects')->where('slug', 'tondo')->value('id') ?? '';
        }
        return $id;
    }

    private function inscrireParticipant(TondoUser $user, TondoCagnotte $cagnotte): void
    {
        $deja = DB::table('tondo_participants')
            ->where('cagnotte_id', $cagnotte->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($deja) {
            return;
        }

        DB::table('tondo_participants')->insert([
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

        DB::table('tondo_cagnottes')
            ->where('id', $cagnotte->id)
            ->increment('nombre_inscrits');
    }

    private function maskPhoneNum(string $phone): string
    {
        $clean = preg_replace('/[^\d+]/', '', $phone);
        if (strlen($clean) < 6) return $clean;
        $prefix = substr($clean, 0, strlen($clean) - 6);
        return $prefix . str_repeat('*', strlen($clean) - strlen($prefix) - 2) . substr($clean, -2);
    }
}
