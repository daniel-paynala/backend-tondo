<?php

namespace App\Services\WhatsApp;

use App\Models\TondoCagnotte;
use App\Models\TondoUser;

/**
 * Moteur conversationnel du bot WhatsApp Tondo.
 *
 * Chaque méthode publique reçoit ($numero, $texte) et retourne
 * une chaîne de caractères (le message à envoyer en réponse).
 *
 * Machine à états pilotée par SessionService :
 *
 *   [aucune session]
 *        │ premier message
 *        ▼
 *      MENU ──► 1 ──► cotiser.ref ──► cotiser.montant ──► [fin]
 *               2 ──► rejoindre.ref ──► [fin]
 *               3 ──► creer.type ──► ...
 *               4 ──► gerer.menu ──► ...
 *               5 ──► aide
 */
class BotService
{
    public function __construct(private SessionService $session) {}

    // ── Point d'entrée ────────────────────────────────────────────────────────

    public function traiter(string $numero, string $texte): string
    {
        $texte = trim($texte);
        $etape = $this->session->etape($numero);

        // Commande universelle de reset / retour menu
        if ($this->estRetourMenu($texte)) {
            $this->session->reset($numero);
            return $this->afficherMenu($numero);
        }

        // Pas de session → première arrivée
        if ($etape === null) {
            return $this->premiereArrivee($numero, $texte);
        }

        // Dispatcher selon l'étape courante
        return match (true) {
            $etape === 'menu'              => $this->handleMenu($numero, $texte),
            $etape === 'cotiser.ref'       => $this->handleCotiserRef($numero, $texte),
            $etape === 'cotiser.montant'   => $this->handleCotiserMontant($numero, $texte),
            $etape === 'rejoindre.ref'     => $this->handleRejoindreRef($numero, $texte),
            $etape === 'creer.type'        => $this->handleCreerType($numero, $texte),
            $etape === 'gerer.menu'        => $this->handleGererMenu($numero, $texte),
            default                        => $this->afficherMenu($numero),
        };
    }

    // ── Première arrivée ──────────────────────────────────────────────────────

    private function premiereArrivee(string $numero, string $texte): string
    {
        $this->session->set($numero, 'menu');
        return $this->afficherMenu($numero);
    }

    // ── Menu principal ────────────────────────────────────────────────────────

    private function afficherMenu(string $numero): string
    {
        $user    = $this->utilisateur($numero);
        $prenom  = $user ? ucfirst(mb_strtolower($user->prenom)) : 'cher client';
        $this->session->set($numero, 'menu');

        return <<<TXT
        🎉 *Bienvenue sur Tondo !*

        Bonjour {$prenom} 👋

        Que souhaitez-vous faire ?

        1️⃣  *Cotiser* à une cagnotte
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
            default => $this->optionInvalide(),
        };
    }

    // ── 1 — Cotiser ───────────────────────────────────────────────────────────

    private function demarrerCotiser(string $numero): string
    {
        $this->session->set($numero, 'cotiser.ref');
        return <<<TXT
        💰 *Cotiser à une cagnotte*

        Entrez la *référence* de la cagnotte
        (numéro à 4-6 chiffres fourni par l'organisateur).

        _Tapez_ *0* _pour revenir au menu._
        TXT;
    }

    private function handleCotiserRef(string $numero, string $texte): string
    {
        $ref = preg_replace('/\D/', '', $texte);

        if (! $ref) {
            return "⚠️ Référence invalide. Entrez un numéro à 4-6 chiffres.\n_Tapez_ *0* _pour revenir au menu._";
        }

        $cagnotte = TondoCagnotte::where('reference', $ref)->first();

        if (! $cagnotte) {
            return "❌ Aucune cagnotte trouvée avec la référence *#{$ref}*.\nVérifiez et réessayez.\n\n_Tapez_ *0* _pour revenir au menu._";
        }

        if ($cagnotte->statut === 'cloturee') {
            return "❌ La cagnotte *{$cagnotte->titre}* est clôturée.\nLes paiements ne sont plus acceptés.\n\n_Tapez_ *0* _pour revenir au menu._";
        }

        $this->session->set($numero, 'cotiser.montant', [
            'reference' => $ref,
            'titre'     => $cagnotte->titre,
            'type'      => $cagnotte->type,
            'montant_par_cycle' => $cagnotte->montant_par_cycle,
        ]);

        // Tontine → montant fixe, pas besoin de demander
        if ($cagnotte->type === 'tontine_periodique' && $cagnotte->montant_par_cycle) {
            $montant = number_format($cagnotte->montant_par_cycle, 0, ',', ' ');
            return $this->confirmerCotisation($numero, $ref, $cagnotte->titre, (int) $cagnotte->montant_par_cycle);
        }

        $appUrl = config('app.url', 'http://51.44.254.213');
        return <<<TXT
        ✅ *{$cagnotte->titre}* · #{$ref}

        Quel montant souhaitez-vous cotiser ?
        _(minimum 100 FCFA)_

        Ou payez directement via ce lien :
        👉 {$appUrl}/cagnottes/{$ref}

        _Tapez_ *0* _pour revenir au menu._
        TXT;
    }

    private function handleCotiserMontant(string $numero, string $texte): string
    {
        $montant = (int) preg_replace('/\D/', '', $texte);
        $data    = $this->session->data($numero);

        if ($montant < 100) {
            return "⚠️ Le montant minimum est *100 FCFA*.\nEntrez un montant valide.\n\n_Tapez_ *0* _pour revenir au menu._";
        }

        if ($montant > 500_000) {
            return "⚠️ Le montant maximum par transaction est *500 000 FCFA*.\nEntrez un montant valide.\n\n_Tapez_ *0* _pour revenir au menu._";
        }

        return $this->confirmerCotisation($numero, $data['reference'], $data['titre'], $montant);
    }

    private function confirmerCotisation(string $numero, string $ref, string $titre, int $montant): string
    {
        $appUrl  = config('app.url', 'http://51.44.254.213');
        $fmt     = number_format($montant, 0, ',', ' ');
        $this->session->reset($numero);

        return <<<TXT
        ✅ *Paiement — {$titre}*

        Montant : *{$fmt} FCFA* _(+ frais opérateur)_

        Cliquez sur ce lien pour finaliser le paiement via Mobile Money :
        👉 {$appUrl}/cagnottes/{$ref}

        ℹ️ _Les frais sont appliqués au moment du paiement._

        _Tapez_ *0* _pour revenir au menu._
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

        _Tapez_ *0* _pour revenir au menu._
        TXT;
    }

    private function handleRejoindreRef(string $numero, string $texte): string
    {
        $ref      = preg_replace('/\D/', '', $texte);
        $appUrl   = config('app.url', 'http://51.44.254.213');

        if (! $ref) {
            return "⚠️ Référence invalide.\n\n_Tapez_ *0* _pour revenir au menu._";
        }

        $cagnotte = TondoCagnotte::where('reference', $ref)->first();

        if (! $cagnotte) {
            return "❌ Référence *#{$ref}* introuvable.\n\n_Tapez_ *0* _pour revenir au menu._";
        }

        $this->session->reset($numero);

        return <<<TXT
        ✅ *{$cagnotte->titre}* · #{$ref}

        Rejoignez cette cagnotte en cliquant ici :
        👉 {$appUrl}/cagnottes/{$ref}

        _Tapez_ *0* _pour revenir au menu._
        TXT;
    }

    // ── 3 — Créer ─────────────────────────────────────────────────────────────

    private function demarrerCreer(string $numero): string
    {
        $appUrl = config('app.url', 'http://51.44.254.213');
        $this->session->set($numero, 'creer.type');

        return <<<TXT
        ✨ *Créer une cagnotte*

        La création se fait depuis l'application Tondo :
        👉 {$appUrl}/cagnottes/nouvelle

        Connectez-vous avec votre numéro et suivez les étapes.

        _Tapez_ *0* _pour revenir au menu._
        TXT;
    }

    private function handleCreerType(string $numero, string $texte): string
    {
        $this->session->reset($numero);
        return $this->afficherMenu($numero);
    }

    // ── 4 — Gérer ─────────────────────────────────────────────────────────────

    private function demarrerGerer(string $numero): string
    {
        $appUrl = config('app.url', 'http://51.44.254.213');
        $user   = $this->utilisateur($numero);
        $this->session->set($numero, 'gerer.menu');

        if (! $user) {
            return <<<TXT
            🔒 *Gérer mes cagnottes*

            Connectez-vous d'abord depuis l'app Tondo :
            👉 {$appUrl}/connexion

            _Tapez_ *0* _pour revenir au menu._
            TXT;
        }

        $cagnottes = TondoCagnotte::where('user_id', $user->id)
            ->where('statut', '!=', 'cloturee')
            ->orderBy('date_creation', 'desc')
            ->limit(5)
            ->get();

        if ($cagnottes->isEmpty()) {
            $this->session->reset($numero);
            return <<<TXT
            📭 Vous n'avez aucune cagnotte active.

            Tapez *3* pour en créer une.

            _Tapez_ *0* _pour revenir au menu._
            TXT;
        }

        $lignes = $cagnottes->map(fn ($c, $i) =>
            ($i + 1) . ". *{$c->titre}* · #{$c->reference}"
        )->implode("\n");

        $appUrl = config('app.url', 'http://51.44.254.213');
        $this->session->reset($numero);

        return <<<TXT
        📋 *Vos cagnottes actives*

        {$lignes}

        Gérez-les depuis l'app :
        👉 {$appUrl}/dashboard

        _Tapez_ *0* _pour revenir au menu._
        TXT;
    }

    private function handleGererMenu(string $numero, string $texte): string
    {
        $this->session->reset($numero);
        return $this->afficherMenu($numero);
    }

    // ── 5 — Aide ──────────────────────────────────────────────────────────────

    private function afficherAide(): string
    {
        $this->session->reset(func_get_args()[0] ?? '');
        return <<<TXT
        ❓ *Aide & support Tondo*

        *Comment cotiser ?*
        Tapez *1* depuis le menu, entrez la référence de la cagnotte, puis suivez le lien de paiement.

        *Comment rejoindre une cagnotte ?*
        Demandez la référence à l'organisateur, puis tapez *2* depuis le menu.

        *Frais*
        Les frais (2 % Tondo + frais opérateur) sont à la charge du cotisant et s'appliquent au moment du paiement.

        *Une question ?*
        Contactez-nous à support@tondo.ga ou via l'app Tondo.

        _Tapez_ *0* _pour revenir au menu principal._
        TXT;
    }

    // ── Utilitaires ───────────────────────────────────────────────────────────

    private function optionInvalide(): string
    {
        return "⚠️ Option non reconnue.\nTapez un chiffre entre *1* et *5*.\n\n_Tapez_ *0* _pour revenir au menu._";
    }

    private function estRetourMenu(string $texte): bool
    {
        return in_array(trim($texte), ['0', 'menu', 'retour', 'annuler', 'cancel'], true);
    }

    private function utilisateur(string $numero): ?TondoUser
    {
        // Normalise : garde les 9 derniers chiffres pour matcher les formats variés
        $suffixe = substr(preg_replace('/\D/', '', $numero), -9);
        return TondoUser::where('telephone', 'like', "%{$suffixe}")->first();
    }
}
