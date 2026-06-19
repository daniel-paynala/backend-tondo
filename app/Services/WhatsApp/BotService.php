<?php

namespace App\Services\WhatsApp;

use App\Models\TondoCagnotte;
use App\Models\TondoPaiementEnAttente;
use App\Models\TondoUser;
use App\Services\ReceiptService;
use App\Services\TwilioVerifyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moteur conversationnel du bot WhatsApp Tonji.
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
        private TwilioSenderService  $twilio,
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

        // ── Deep link TONJI [ref] — contourne l'état de session ──────────────
        if (preg_match('/^TONJI\s+(\d{4,6})$/i', $texte, $m)) {
            return $this->handleDeepLink($numero, $m[1]);
        }

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
        🎉 *Bienvenue sur Tonji !*

        Que souhaitez-vous faire ?

        1️⃣  *Cotiser*
        2️⃣  *Rejoindre* une tontine
        3️⃣  *Créer* (Tontine ou Cagnotte)
        4️⃣  *Gérer* (Tontine ou Cagnotte)
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

        Entrez le *Numéro de tontine ou de cagnotte*
        (6 chiffres, fourni par l'organisateur).

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    private function handleCotiserRef(string $numero, string $texte): string
    {
        $ref      = preg_replace('/\D/', '', $texte);
        $cagnotte = $ref ? TondoCagnotte::where('reference', $ref)->first() : null;

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Numéro de tontine/cagnotte *N°{$ref}* introuvable.\nVérifiez et réessayez.");
        }

        if ($cagnotte->statut === 'cloturee') {
            return "❌ La tontine ou cagnotte *{$cagnotte->titre}* est clôturée.\n\n#️⃣ _pour revenir en arrière_";
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
                Les paiements seront disponibles une fois tous les membres inscrits.
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

    private function handleCotiserNumero(string $numero, string $texte): string|array
    {
        $numeroSaisi = $this->normaliserNumero($texte);

        if (! $numeroSaisi) {
            return "⚠️ Numéro invalide.\nFormat attendu : *0XXXXXXXX*\n\n#️⃣ _pour revenir en arrière_";
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

        // ── Cotisation : nouvel utilisateur → compte silencieux + paiement direct ──
        $user = $this->cotisationSvc->creerCompteLight(
            nom: '',
            prenom: '',
            numeroE164: $numeroSaisi,
            projectId: $projectId,
        );

        return $this->lancerPaiement($numero, $user, $data, $numeroSaisi);
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

            #️⃣ _pour revenir en arrière_
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
            return "❌ Une erreur technique est survenue. Veuillez réessayer.\n\n#️⃣ _pour revenir en arrière_";
        }
    }

    private function _lancerPaiement(string $numero, TondoUser $user, array $data, string $numeroPayeur): string|array
    {
        $cagnotte = TondoCagnotte::find($data['cagnotte_id']);

        if (! $cagnotte) {
            $this->session->reset($numero);
            return "❌ Erreur : cagnotte introuvable.\n\n#️⃣ _pour revenir en arrière_";
        }

        // Utiliser le numéro saisi comme numéro de paiement
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

        // Surveillance automatique : vérification toutes les minutes par le scheduler.
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

            #️⃣ _pour revenir en arrière_
            TXT;
        }

        $statut = $this->cotisationSvc->verifierStatut($transId, $data['project_id']);

        if ($statut === 'succes') {
            // Supprimer du suivi automatique pour éviter un double-envoi par le scheduler.
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
                Log::error('BotService: échec génération reçu (attente→succes)', [
                    'trans_id' => $transId,
                    'err'      => $e->getMessage(),
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

    public function recu(?TondoUser $user, ?TondoCagnotte $cagnotte, array $resultat, string $canal = 'WhatsApp', ?string $pdfUrl = null): string
    {
        $montant = number_format((int) ($resultat['montant_net'] ?? 0), 0, ',', ' ');
        $titre   = $cagnotte ? $cagnotte->titre : '—';
        $ref     = $cagnotte ? 'N°' . $cagnotte->reference : '';
        $prenom  = $user ? ucfirst(mb_strtolower($user->prenom)) : '';
        $merci   = ($prenom && strtolower($prenom) !== 'anonyme') ? "Merci {$prenom} 🙏" : 'Merci 🙏';
        $ligneRecu = $pdfUrl ? "\n📄 *Votre reçu :* {$pdfUrl}" : '';

        return <<<TXT
        ✅ *Paiement confirmé !*

        {$merci}
        Votre paiement de *{$montant} FCFA* pour *{$titre} {$ref}* a été enregistrée.{$ligneRecu}

        ————————————————
        🎉 *Que souhaitez-vous faire ?*

        1️⃣  *Cotiser*
        2️⃣  *Rejoindre* une tontine
        3️⃣  *Créer* (Tontine ou Cagnotte)
        4️⃣  *Gérer* (Tontine ou Cagnotte)
        5️⃣  *Aide* & support

        _Tapez le numéro de votre choix._
        TXT;
    }

    // ── 2 — Rejoindre ─────────────────────────────────────────────────────────

    private function demarrerRejoindre(string $numero): string
    {
        $this->session->set($numero, 'rejoindre.ref');
        return <<<TXT
        🤝 *Rejoindre une tontine ou une cagnotte*

        Entrez le *Numéro de tontine ou de cagnotte*
        (6 chiffres, fourni par l'organisateur).

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    private function handleRejoindreRef(string $numero, string $texte): string
    {
        $ref      = preg_replace('/\D/', '', $texte);
        $cagnotte = $ref ? TondoCagnotte::where('reference', $ref)->first() : null;

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Numéro de tontine/cagnotte *N°{$ref}* introuvable.\nVérifiez et réessayez.");
        }

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

        // Déjà membre ?
        if ($user) {
            $dejaMembre = DB::table('tondo_participants')
                ->where('cagnotte_id', $cagnotte->id)
                ->where('user_id', $user->id)
                ->exists();

            if ($dejaMembre) {
                return $this->erreurEtMenu($numero, "ℹ️ Ce numéro est déjà membre de *{$cagnotte->titre}* (N°{$ref}).");
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

            Bienvenue *{$prenom}* ! Vous avez rejoint la {$type} *{$cagnotte->titre}* (N°{$ref}).

            TXT . "\n" . $this->afficherMenu($numero);
        }

        // Nouvel utilisateur → demander nom + prénom
        $this->session->set($numero, 'rejoindre.nom_prenom', array_merge($data, [
            'numero_payeur' => $numeroSaisi,
        ]));

        return <<<TXT
        👤 *Nouveau sur Tonji*

        Vous n'avez pas encore de compte. On va en créer un rapidement.

        Entrez votre *nom* puis votre *prénom*, chacun sur une ligne :

        _Exemple :_
        MBOULA
        Jean

        #️⃣ _pour revenir en arrière_
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

            #️⃣ _pour revenir en arrière_
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

        Bienvenue *{$prenom}* ! Vous avez rejoint la {$type} *{$cagnotte->titre}* (N°{$ref}).

        TXT . "\n" . $this->afficherMenu($numero);
    }

    // ── 3 — Créer ─────────────────────────────────────────────────────────────

    private function demarrerCreer(string $numero): string
    {
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

    private function routerCreer(string $numero, string $etape, string $texte): string
    {
        return match ($etape) {
            'creer.type'                       => $this->handleCreerType($numero, $texte),
            'creer.cotisation.nom'             => $this->handleCreerCotisationNom($numero, $texte),
            'creer.cotisation.montant_cible'   => $this->handleCreerCotisationMontantCible($numero, $texte),
            'creer.cotisation.date_fin'        => $this->handleCreerCotisationDateFin($numero, $texte),
            'creer.tontine.nom'             => $this->handleCreerTontineNom($numero, $texte),
            'creer.tontine.nb_participants' => $this->handleCreerTontineNbParticipants($numero, $texte),
            'creer.tontine.montant_cycle'   => $this->handleCreerTontineMontantCycle($numero, $texte),
            'creer.tontine.periodicite'     => $this->handleCreerTontinePeriodicite($numero, $texte),
            'creer.tontine.jour_mois'       => $this->handleCreerTontineJourMois($numero, $texte),
            'creer.numero'                     => $this->handleCreerNumero($numero, $texte),
            'creer.nom_prenom'                 => $this->handleCreerNomPrenom($numero, $texte),
            'creer.certification'              => $this->handleCreerCertification($numero, $texte),
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

    private function handleCreerCotisationNom(string $numero, string $texte): string
    {
        $titre = trim($texte);
        if (mb_strlen($titre) < 3 || mb_strlen($titre) > 120) {
            return "⚠️ Nom invalide (3 à 120 caractères).\n\n#️⃣ _pour revenir en arrière_";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'creer.numero', array_merge($data, [
            'titre'         => $titre,
            'montant_cible' => 0,
            'date_fin'      => null,
        ]));

        return $this->demanderNumeroCreateur();
    }

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

    private function handleCreerTontineNom(string $numero, string $texte): string
    {
        $titre = trim($texte);
        if (mb_strlen($titre) < 3 || mb_strlen($titre) > 120) {
            return "⚠️ Nom invalide (3 à 120 caractères).\n\n#️⃣ _pour revenir en arrière_";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'creer.tontine.nb_participants', array_merge($data, [
            'titre' => $titre,
        ]));

        return <<<TXT
        Nombre de *participants* ?
        _(entre 2 et 200)_

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    private function handleCreerTontineNbParticipants(string $numero, string $texte): string
    {
        $nb = (int) preg_replace('/\D/', '', $texte);
        if ($nb < 2 || $nb > 200) {
            return "⚠️ Nombre invalide. Entre *2* et *200* participants.\n\n#️⃣ _pour revenir en arrière_";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'creer.tontine.montant_cycle', array_merge($data, [
            'nombre_participants' => $nb,
        ]));

        return <<<TXT
        Montant *récupéré par participant* ? _(sans les frais)_
        (en FCFA)

        #️⃣ _pour revenir en arrière_
        TXT;
    }

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

    private function demanderNumeroCreateur(): string
    {
        return <<<TXT
        📱 Votre *numéro Mobile Money* ?
        _(format : *0XXXXXXXX*)_

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    private function handleCreerNumero(string $numero, string $texte): string
    {
        $numeroSaisi = $this->normaliserNumero($texte);
        if (! $numeroSaisi) {
            return "⚠️ Numéro invalide. Format : *0XXXXXXXX*\n\n#️⃣ _pour revenir en arrière_";
        }

        $data      = $this->session->data($numero);
        $projectId = $data['project_id'] ?? $this->tondoProjectId();
        $user      = $this->utilisateurParNumero($numeroSaisi, $projectId);

        if ($user) {
            // Compte light (profil vide) — compléter avant de pouvoir créer
            if (trim($user->nom) === '' || trim($user->prenom) === '') {
                $this->session->set($numero, 'creer.nom_prenom', array_merge($data, [
                    'user_id'       => $user->id,
                    'numero_payeur' => $numeroSaisi,
                ]));
                return <<<TXT
                👤 *Complétez votre profil*

                Pour créer une cagnotte, nous avons besoin de votre identité.

                Entrez votre *nom* puis votre *prénom*, chacun sur une ligne :

                _Exemple :_
                MBOULA
                Jean

                #️⃣ _pour revenir en arrière_
                TXT;
            }

            $merged = array_merge($data, [
                'user_id'        => $user->id,
                'numero_payeur'  => $numeroSaisi,
                'numero_retrait' => $numeroSaisi,
            ]);
            $this->session->set($numero, 'creer.recap', $merged);
            return $this->construireRecap($merged, $numeroSaisi);
        }

        $this->session->set($numero, 'creer.nom_prenom', array_merge($data, [
            'numero_payeur' => $numeroSaisi,
        ]));

        return <<<TXT
        👤 *Nouveau sur Tonji*

        Vous n'avez pas encore de compte. On va en créer un.

        Entrez votre *nom* puis votre *prénom*, chacun sur une ligne :

        _Exemple :_
        MBOULA
        Jean

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    private function handleCreerNomPrenom(string $numero, string $texte): string
    {
        $lignes = array_values(array_filter(array_map('trim', explode("\n", $texte))));

        if (count($lignes) < 2) {
            return <<<TXT
            ⚠️ Format incorrect. Entrez *nom* puis *prénom*, chacun sur une ligne.

            #️⃣ _pour revenir en arrière_
            TXT;
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'creer.certification', array_merge($data, [
            'nom'    => mb_strtoupper(trim($lignes[0])),
            'prenom' => ucfirst(mb_strtolower(trim($lignes[1]))),
        ]));

        return $this->messageCertification();
    }

    private function handleCreerCertification(string $numero, string $texte): string
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

        $numeroRetrait = $data['numero_payeur'];
        $merged        = array_merge($data, [
            'user_id'        => $user->id,
            'numero_retrait' => $numeroRetrait,
        ]);
        $this->session->set($numero, 'creer.recap', $merged);

        return $this->construireRecap($merged, $numeroRetrait);
    }

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
            Participants : *{$data['nombre_participants']}*
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

    private function cguTexte(): string
    {
        return <<<TXT
        En confirmant, vous acceptez les conditions d'utilisation Tonji : https://tonji.ga/cgu

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
            return $this->erreurEtMenu($numero, "❌ Erreur lors de la création. Réessayez ou contactez support@tonji.ga.");
        }

        $type      = $cagnotte->type === 'tontine_periodique' ? 'tontine' : 'cagnotte';
        $typeLabel = $cagnotte->type === 'tontine_periodique' ? 'tontine' : 'cagnotte';
        $prenom = ucfirst(mb_strtolower($user->prenom));
        $ref    = $cagnotte->reference;
        $botNum = ltrim(config('tondo.whatsapp_numero', ''), '+');
        $lienWa = $botNum
            ? "\nhttps://wa.me/{$botNum}?text=" . rawurlencode("TONJI {$ref}")
            : " N°*{$ref}*";

        return <<<TXT
        🎉 *{$cagnotte->titre}* créée avec succès !

        Félicitations *{$prenom}* !
        Votre {$type} est active.

        *Numéro de {$typeLabel} : N°{$ref}*
        Partagez ce lien à vos participants :{$lienWa}

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
        📋 *Gérer mes tontines & cagnottes*

        Votre *numéro Mobile Money* ?
        _(format : *0XXXXXXXX*)_

        #️⃣ _pour revenir en arrière_
        TXT;
    }

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

        // OTP validé — continuer le flow normal
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

    private function messageCertification(): string
    {
        return <<<TXT
        🔞 *Confirmation de majorité*

        Pour utiliser Tonji, vous devez avoir *18 ans ou plus*.

        Tapez *1* pour certifier être majeur et accepter les conditions d'utilisation : https://tonji.ga/cgu

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    private function afficherListeCagnottes(string $numero, TondoUser $user, array $data): string
    {
        $cagnottes = $this->gererCagnotteSvc->cagnottesGerees($user);

        if ($cagnottes->isEmpty()) {
            return $this->erreurEtMenu($numero,
                "📭 Vous n'avez aucune tontine ou cagnotte active.\nTapez *3* pour en créer une."
            );
        }

        $liste    = $cagnottes->values();
        $index    = $liste->map(fn ($c, $i) => ($i + 1) . ". *{$c->titre}* · N°{$c->reference} · "
            . ($c->type === 'tontine_periodique' ? 'Tontine' : 'Cagnotte')
        )->implode("\n");

        $refs = $liste->pluck('reference')->toArray();

        $this->session->set($numero, 'gerer.liste', array_merge($data, ['refs' => $refs]));

        return <<<TXT
        📋 *Vos tontines & cagnottes actives*

        {$index}

        Quelle tontine ou cagnotte souhaitez-vous gérer ?
        _(tapez le numéro de la liste, ou entrez directement le numéro de la tontine ou cagnotte)_

        #️⃣ _pour revenir en arrière_
        TXT;
    }

    private function handleGererListe(string $numero, string $texte): string
    {
        $data  = $this->session->data($numero);
        $refs  = $data['refs'] ?? [];
        $n     = count($refs);
        $input = trim($texte);
        $choix = (int) $input;

        // Numéro de cagnotte saisi directement → priorité sur le choix positionnel
        if (in_array($input, $refs, true)) {
            $ref = $input;
        } elseif ($choix >= 1 && $choix <= $n) {
            $ref = $refs[$choix - 1];
        } else {
            return "⚠️ Tapez un chiffre entre *1* et *{$n}*, ou entrez directement le *numéro de la tontine ou cagnotte*.\n\n#️⃣ _pour revenir en arrière_";
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
                $nom  = $brut === '' ? 'Anonyme' : $brut;
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
            🔄 *{$titre}* · N°{$ref}
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
            ⏳ *{$titre}* · N°{$ref}
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
        ⏳ *{$titre}* · N°{$ref}
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

    private function executerDemarrerTontine(string $numero, TondoCagnotte $cagnotte): string
    {
        DB::table('tondo_cagnottes')->where('id', $cagnotte->id)->update([
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

    private function executerSupprimerTontine(string $numero, TondoCagnotte $cagnotte): string
    {
        DB::table('tondo_cagnottes')->where('id', $cagnotte->id)->update([
            'statut'     => 'cloturee',
            'updated_at' => now(),
        ]);

        $data = $this->session->data($numero);

        return <<<TXT
        ✅ *Tontine supprimée.*

        La tontine *{$cagnotte->titre}* a bien été supprimée.

        TXT . "\n" . $this->retourListeCagnottes($numero, $data);
    }

    private function demarrerEditionOrdre(string $numero, TondoCagnotte $cagnotte, array $data): string
    {
        $participants = DB::table('tondo_participants')
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

        #️⃣ _pour revenir en arrière_
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
            return "⚠️ Envoyez exactement *{$n}* paires (une par participant).\n\n#️⃣ _pour revenir en arrière_";
        }

        // Vérifier que chaque position source et destination est unique
        $sources = array_keys($pairs);
        $dests   = array_values($pairs);
        if (count(array_unique($sources)) !== $n || count(array_unique($dests)) !== $n) {
            return "⚠️ Chaque position doit apparaître exactement *une fois* en source et en destination.\n\n#️⃣ _pour revenir en arrière_";
        }

        foreach ($pairs as $ancien => $nouveau) {
            $id = $ids[$ancien - 1] ?? null;
            if ($id) {
                DB::table('tondo_participants')
                    ->where('id', $id)
                    ->update(['ordre_passage' => $nouveau, 'updated_at' => now()]);
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

        $nbTotal = $paiements->count();
        $total   = number_format((int) $paiements->sum('montant'), 0, ',', ' ');
        $cinq    = $paiements->take(5);
        $lignes  = $cinq->map(fn ($p) =>
            \Carbon\Carbon::parse($p->updated_at)->format('d/m') .
            ' · ' . $p->cotisant .
            ' · *' . number_format((int) $p->montant, 0, ',', ' ') . ' FCFA*'
        )->implode("\n");

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
            return $this->retourMenuCagnotte($numero, $cagnotte, $data);
        }

        return "⚠️ Tapez *0* pour revenir au menu.\n\n#️⃣ _pour revenir en arrière_";
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
            return "Entrez le *numéro* Mobile Money du bénéficiaire :\n_(format : *0XXXXXXXX*)_\n\n#️⃣ _pour revenir en arrière_";
        }

        return "⚠️ Tapez *1* pour Mon numéro ou *2* pour Autre numéro.\n\n#️⃣ _pour revenir en arrière_";
    }

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

    private function demanderMontantReversement(string $masque): string
    {
        return <<<TXT
        Bénéficiaire : *{$masque}*

        Quel *montant* souhaitez-vous reverser ? (en FCFA)
        _(min 100 — ne peut pas dépasser le solde disponible)_

        #️⃣ _pour revenir en arrière_
        TXT;
    }

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
                DB::table('tondo_cagnottes')->where('id', $cagnotte->id)->update([
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

        // Solde > 0
        if ($texte === '1') {
            // Garder le numéro de retrait enregistré
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
        $montant       = (int) $cagnotte->montant_collecte;

        try {
            $result = $this->gererCagnotteSvc->initierReversement(
                cagnotte:   $cagnotte,
                gerant:     $gerant,
                numeroE164: $numeroRetrait,
                montant:    $montant,
            );
        } catch (\RuntimeException $e) {
            return "❌ " . $e->getMessage() . "\n\nLa cagnotte reste ouverte.\n\n" . $this->retourMenuCagnotte($numero, $cagnotte, $data);
        } catch (\Throwable $e) {
            Log::error('handleGererFermerOtp: erreur reversement', ['err' => $e->getMessage()]);
            return "❌ Erreur technique. Contactez support@tonji.ga.\n\nLa cagnotte reste ouverte.\n\n" . $this->retourMenuCagnotte($numero, $cagnotte, $data);
        }

        // Reversement confirmé → clôturer
        DB::table('tondo_cagnottes')->where('id', $cagnotte->id)->update([
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
     * Point d'entrée deep link : détecte le type et l'état de la cagnotte,
     * puis injecte la session au bon endroit du flow existant.
     *
     * Règles :
     *  - Tontine non démarrée (date_debut IS NULL) → rejoindre.numero
     *  - Tontine démarrée                           → cotiser.numero (montant fixe)
     *  - Cotisation ouverte                         → deeplink.choix (rejoindre OU cotiser)
     */
    private function handleDeepLink(string $numero, string $ref): string
    {
        $cagnotte = TondoCagnotte::where('reference', $ref)->first();

        if (! $cagnotte || $cagnotte->statut === 'cloturee') {
            $this->session->set($numero, 'menu');
            return "❌ Cette tontine ou cagnotte n'est pas disponible.\n\n" . $this->afficherMenu($numero);
        }

        // Tontine non démarrée → rejoindre directement
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

        // Tontine démarrée → cotiser montant fixe directement
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

        // Cotisation → cotiser directement (rejoindre est automatique au paiement)
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
     * En prod : envoie un vrai SMS via Twilio Verify, retourne [null, ''].
     * En non-prod : génère 123456, pas d'envoi, retourne le code + hint affiché.
     *
     * @return array{0: string|null, 1: string}  [otp_local|null, hint_message]
     */
    private function envoyerOtp(string $numeroE164): array
    {
        // Bypass explicite via TONDO_OTP_BYPASS=123456 dans .env (test multi-utilisateurs)
        $bypass = config('tondo.otp_bypass');
        if ($bypass) {
            return [$bypass, "\n_(Test : code = *{$bypass}*)_"];
        }

        try {
            $this->twilioVerify->sendOtp($numeroE164);
        } catch (\Throwable $e) {
            Log::warning('envoyerOtp: échec Twilio Verify', [
                'numero' => $numeroE164,
                'err'    => $e->getMessage(),
            ]);
        }

        return [null, ''];
    }

    /**
     * Si TONDO_OTP_BYPASS est défini : accepte ce code universel.
     * Sinon : vérifie via Twilio Verify.
     */
    private function verifierOtp(string $numeroE164, string $codeSaisi, ?string $otpLocal): bool
    {
        $bypass = config('tondo.otp_bypass');
        if ($bypass) {
            return $codeSaisi === $bypass || $codeSaisi === ($otpLocal ?? $bypass);
        }

        try {
            return $this->twilioVerify->checkOtp($numeroE164, $codeSaisi);
        } catch (\Throwable $e) {
            Log::error('verifierOtp: erreur checkOtp Twilio', ['err' => $e->getMessage()]);
            return false;
        }
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
