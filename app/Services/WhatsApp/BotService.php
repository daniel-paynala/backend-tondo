<?php

namespace App\Services\WhatsApp;

use App\Jobs\WhatsApp\VerifierPaiementJob;
use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use App\Services\ReceiptService;
use Illuminate\Support\Facades\DB;

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
 *        ──► 2 ──► rejoindre.ref ──► lien
 *        ──► 3 ──► lien app web
 *        ──► 4 ──► liste cagnottes
 *        ──► 5 ──► aide
 *
 *  cotiser.attente ──► OK ──► vérif statut ──► reçu / échec / toujours en cours
 */
class BotService
{
    public function __construct(
        private SessionService    $session,
        private CotisationService $cotisationSvc,
        private ReceiptService    $receiptSvc,
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
            '5'     => $this->afficherAide(),
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

        _Tapez_ *#* _pour revenir au menu._
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
            return "❌ La cagnotte *{$cagnotte->titre}* est clôturée.\n\n_Tapez_ *#* _pour revenir au menu._";
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

            _Tapez_ *#* _pour revenir au menu._
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

        _Tapez_ *#* _pour revenir au menu._
        TXT;
    }

    // ── 1 — Cotiser : montant (cotisation uniquement) ─────────────────────────

    private function handleCotiserMontant(string $numero, string $texte): string
    {
        $montant = (int) preg_replace('/\D/', '', $texte);

        if ($montant < 100) {
            return "⚠️ Montant minimum : *100 FCFA*.\nEntrez un montant valide, ou tapez *#* pour annuler.";
        }

        if ($montant > 500_000) {
            return "⚠️ Montant maximum par transaction : *500 000 FCFA*.\nEntrez un montant valide, ou tapez *#* pour annuler.";
        }

        $data = $this->session->data($numero);
        $this->session->set($numero, 'cotiser.numero', array_merge($data, ['montant' => $montant]));

        return <<<TXT
        💵 Montant : *{$montant} FCFA*

        Entrez votre *numéro de téléphone* Mobile Money
        (format : *0XXXXXXXX*).

        _Tapez_ *#* _pour revenir au menu._
        TXT;
    }

    // ── 1 — Cotiser : numéro de téléphone ────────────────────────────────────

    private function handleCotiserNumero(string $numero, string $texte): string|array
    {
        $numeroSaisi = $this->normaliserNumero($texte);

        if (! $numeroSaisi) {
            return "⚠️ Numéro invalide.\nFormat attendu : *0XXXXXXXX*\n\n_Tapez_ *#* _pour annuler._";
        }

        $data     = $this->session->data($numero);
        $type     = $data['type'] ?? '';
        $projectId = $data['project_id'] ?? $this->tondoProjectId();

        // Chercher l'utilisateur par ce numéro
        $user = $this->utilisateurParNumero($numeroSaisi, $projectId);

        // ── Tontine : vérifier que c'est bien un participant ──────────────────
        if ($type === 'tontine_periodique') {
            if (! $user) {
                // Pas de compte = pas inscrit à la tontine
                $this->session->reset($numero);
                return <<<TXT
                ❌ *Vous n'êtes pas inscrit à cette tontine.*

                Demandez à l'organisateur de vous ajouter en tant que participant.

                TXT . "\n" . $this->afficherMenu($numero);
            }

            $estParticipant = DB::table('tondo_participants')
                ->where('cagnotte_id', $data['cagnotte_id'])
                ->where('user_id', $user->id)
                ->exists();

            if (! $estParticipant) {
                $this->session->reset($numero);
                return <<<TXT
                ❌ *Vous n'êtes pas inscrit à cette tontine.*

                Demandez à l'organisateur de vous ajouter en tant que participant.

                TXT . "\n" . $this->afficherMenu($numero);
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

        _Tapez_ *#* _pour annuler._
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

            _Tapez_ *#* _pour annuler._
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
            return "❌ Une erreur technique est survenue. Veuillez réessayer.\n\n_Tapez_ *#* _pour revenir au menu._";
        }
    }

    private function _lancerPaiement(string $numero, TondoUser $user, array $data, string $numeroPayeur): string|array
    {
        $cagnotte = TondoCagnotte::find($data['cagnotte_id']);

        if (! $cagnotte) {
            $this->session->reset($numero);
            return "❌ Erreur : cagnotte introuvable.\n\n_Tapez_ *#* _pour revenir au menu._";
        }

        // Utiliser le numéro saisi comme numéro de paiement
        $userPourPaiement         = clone $user;
        $userPourPaiement->numero = $numeroPayeur;

        $resultat = $this->cotisationSvc->initier($userPourPaiement, $cagnotte, (int) $data['montant']);

        if ($resultat['statut'] === 'erreur') {
            $this->session->reset($numero);
            return "❌ Erreur lors de l'initiation du paiement : {$resultat['message']}\n\n_Tapez_ *#* _pour revenir au menu._";
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

        _Tapez_ *#* _pour annuler._
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

            _Tapez_ *#* _pour annuler._
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

        _Tapez_ *#* _pour annuler._
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
        (numéro à 4-6 chiffres fourni par l'organisateur).

        _Tapez_ *#* _pour revenir au menu._
        TXT;
    }

    private function handleRejoindreRef(string $numero, string $texte): string
    {
        $ref      = preg_replace('/\D/', '', $texte);
        $cagnotte = $ref ? TondoCagnotte::where('reference', $ref)->first() : null;
        $appUrl   = config('app.url', 'http://51.44.254.213');

        if (! $cagnotte) {
            return $this->erreurEtMenu($numero, "❌ Référence *#{$ref}* introuvable.\nVérifiez et réessayez.");
        }

        $this->session->reset($numero);

        return <<<TXT
        ✅ *{$cagnotte->titre}* · #{$ref}

        Rejoignez cette cagnotte en cliquant ici :
        👉 {$appUrl}/cagnottes/{$ref}

        _Tapez_ *#* _pour revenir au menu._
        TXT;
    }

    // ── 3 — Créer ─────────────────────────────────────────────────────────────

    private function demarrerCreer(string $numero): string
    {
        $appUrl = config('app.url', 'http://51.44.254.213');
        $this->session->reset($numero);

        return <<<TXT
        ✨ *Créer une cagnotte*

        La création se fait depuis l'application Tondo :
        👉 {$appUrl}/cagnottes/nouvelle

        Connectez-vous avec votre numéro et suivez les étapes.

        _Tapez_ *#* _pour revenir au menu._
        TXT;
    }

    // ── 4 — Gérer ─────────────────────────────────────────────────────────────

    private function demarrerGerer(string $numero): string
    {
        $appUrl    = config('app.url', 'http://51.44.254.213');
        $user      = $this->utilisateur($numero);
        $projectId = $this->tondoProjectId();

        if (! $user) {
            $this->session->reset($numero);
            return <<<TXT
            🔒 *Gérer mes cagnottes*

            Connectez-vous d'abord depuis l'app Tondo :
            👉 {$appUrl}/connexion

            _Tapez_ *#* _pour revenir au menu._
            TXT;
        }

        $cagnottes = TondoCagnotte::where('user_id', $user->id)
            ->where('statut', '!=', 'cloturee')
            ->orderBy('date_creation', 'desc')
            ->limit(5)
            ->get();

        if ($cagnottes->isEmpty()) {
            $this->session->reset($numero);
            return "📭 Vous n'avez aucune cagnotte active.\n\nTapez *3* pour en créer une.\n\n_Tapez_ *#* _pour revenir au menu._";
        }

        $lignes = $cagnottes->map(fn ($c, $i) =>
            ($i + 1) . ". *{$c->titre}* · #{$c->reference}"
        )->implode("\n");

        $this->session->reset($numero);

        return <<<TXT
        📋 *Vos cagnottes actives*

        {$lignes}

        Gérez-les depuis l'app :
        👉 {$appUrl}/dashboard

        _Tapez_ *#* _pour revenir au menu._
        TXT;
    }

    // ── 5 — Aide ──────────────────────────────────────────────────────────────

    private function afficherAide(): string
    {
        return <<<TXT
        ❓ *Aide & support Tondo*

        *Cotiser* → Tapez *1*, entrez la référence et suivez les instructions.
        *Rejoindre* → Tapez *2* et entrez la référence communiquée par l'organisateur.
        *Créer* → Tapez *3* pour accéder à l'application.
        *Gérer* → Tapez *4* pour voir vos cagnottes actives.

        *Les frais* (2 % Tondo + frais opérateur) sont à la charge du cotisant.

        *Une question ?* support@tondo.ga

        _Tapez_ *#* _pour revenir au menu principal._
        TXT;
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
}
