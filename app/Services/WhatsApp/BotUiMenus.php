<?php

namespace App\Services\WhatsApp;

/**
 * Catalogue des versions INTERACTIVES des menus du bot WhatsApp.
 *
 * Couche additive, 100 % découplée de {@see BotService} : on NE modifie jamais
 * le bot texte. Ici on associe une ÉTAPE de session (ex : 'menu') à sa version
 * tappable (boutons ≤3 ou liste ≤10 lignes). Le WebhookController n'utilise ce
 * catalogue que si la variable globale services.whatsapp.ui vaut 'moderne' ;
 * si pour() renvoie null, ou si l'envoi interactif échoue → repli sur le texte.
 *
 * RÈGLE D'OR : l'`id` de chaque bouton/ligne DOIT être exactement le choix
 * qu'attend le bot texte pour cette étape (ex : "1" pour Cotiser au menu
 * principal, "0" pour annuler au récap). WhatsApp renvoie cet id quand
 * l'utilisateur tape ; il est réinjecté tel quel dans BotService::traiter(),
 * donc la logique texte ne bouge pas.
 *
 * Le CORPS du message interactif n'est pas défini ici : le WebhookController
 * reprend le texte du bot débarrassé du bloc « 1️⃣ 2️⃣… / Tapez le numéro »
 * (voir corpsSansOptions), ce qui préserve tout le contenu dynamique (récap,
 * nom de cagnotte, avertissements…) sans dupliquer la liste numérotée.
 *
 * Pour AJOUTER un menu : ajouter un cas dans pour() renvoyant un spec. Un spec
 * peut fixer un corps sur-mesure via la clé 'texte' (sinon corps = texte nettoyé).
 */
class BotUiMenus
{
    /**
     * Version interactive d'une étape, ou null si aucune (→ repli texte).
     *
     * Toutes les étapes de SAISIE (code, montant, numéro, OTP, titre, date…),
     * la LISTE des cagnottes (contenu variable) et l'ATTENTE de paiement
     * retournent null volontairement : elles restent en texte.
     *
     * @param  string|null $etape  Étape courante de la session (lue APRÈS traiter()).
     * @return array{type:string,bouton?:string,texte?:string,boutons?:array<int,array{id:string,titre:string}>,sections?:array<int,array{titre:string,lignes:array<int,array{id:string,titre:string,desc?:string}>}>}|null
     */
    public static function pour(?string $etape): ?array
    {
        return match ($etape) {
            'menu'                       => self::menuPrincipal(),
            'creer.type'                 => self::choixType(),
            'creer.tontine.periodicite'  => self::periodicite(),
            'creer.tontine.jour_mois'    => self::jourDuMois(),
            'creer.recap'                => self::confirmationCreation(),
            'gerer.certification'        => self::certificationMajorite(),
            'gerer.cagnotte'             => self::menuCagnotte(),
            'gerer.fermer.confirm'       => self::confirmationFermeture(),
            default                      => null,   // pas de version interactive → texte
        };
    }

    /**
     * Menu principal en BOUTONS (WhatsApp = 3 boutons max).
     *
     * On expose les 3 actions principales (Cotiser=1, Créer=3, Gérer=4). Les 2
     * options secondaires — Rejoindre (2) et Aide (5) — restent joignables en
     * tapant le chiffre (le bot texte les gère toujours via handleMenu) ; elles
     * sont rappelées dans le corps du message via 'texte'. Les id correspondent
     * exactement au dispatch de handleMenu().
     */
    private static function menuPrincipal(): array
    {
        return [
            'type'    => 'boutons',
            'texte'   => "🎉 *Bienvenue sur Tonji !*\nQue souhaitez-vous faire ?\n\n_Autres : tapez 2 (Rejoindre) ou 5 (Aide)._",
            'boutons' => [
                ['id' => '1', 'titre' => '💰 Cotiser'],
                ['id' => '3', 'titre' => '➕ Créer'],
                ['id' => '4', 'titre' => '📋 Gérer'],
            ],
        ];
    }

    /** Créer → choix du type : Cagnotte (1) ou Tontine (2). 2 options → boutons. */
    private static function choixType(): array
    {
        return [
            'type'    => 'boutons',
            'boutons' => [
                ['id' => '1', 'titre' => '💰 Cagnotte'],
                ['id' => '2', 'titre' => '🔄 Tontine'],
            ],
        ];
    }

    /** Créer tontine → périodicité : 1 sem (1), 2 sem (2), 1 mois (3). 3 → boutons. */
    private static function periodicite(): array
    {
        return [
            'type'    => 'boutons',
            'boutons' => [
                ['id' => '1', 'titre' => '1 semaine'],
                ['id' => '2', 'titre' => '2 semaines'],
                ['id' => '3', 'titre' => '1 mois'],
            ],
        ];
    }

    /** Créer tontine → jour du mois : le 5 (1), le 7 (2), le 15 (3). 3 → boutons. */
    private static function jourDuMois(): array
    {
        return [
            'type'    => 'boutons',
            'boutons' => [
                ['id' => '1', 'titre' => 'Le 5'],
                ['id' => '2', 'titre' => 'Le 7'],
                ['id' => '3', 'titre' => 'Le 15'],
            ],
        ];
    }

    /** Récap création → confirmer (1) / annuler (0). Boutons. */
    private static function confirmationCreation(): array
    {
        return [
            'type'    => 'boutons',
            'boutons' => [
                ['id' => '1', 'titre' => '✅ Confirmer'],
                ['id' => '0', 'titre' => '❌ Annuler'],
            ],
        ];
    }

    /** Gérer → certification de majorité : un seul bouton (id "1"). */
    private static function certificationMajorite(): array
    {
        return [
            'type'    => 'boutons',
            'boutons' => [
                ['id' => '1', 'titre' => '✅ Je certifie'],
            ],
        ];
    }

    /**
     * Menu d'une cagnotte gérée : Historique (1), Reversement (2), Fermer (3),
     * Retour à la liste (4). 4 options → liste. (Le nom de la cagnotte reste
     * dans le corps, via le texte nettoyé du bot.)
     */
    private static function menuCagnotte(): array
    {
        return [
            'type'     => 'liste',
            'bouton'   => 'Actions',
            'sections' => [[
                'titre'  => 'Cette cagnotte',
                'lignes' => [
                    ['id' => '1', 'titre' => 'Historique', 'desc' => 'Qui a payé, combien'],
                    ['id' => '2', 'titre' => 'Reversement', 'desc' => 'Envoyer à un bénéficiaire'],
                    ['id' => '3', 'titre' => 'Fermer la cagnotte'],
                    ['id' => '4', 'titre' => '◀️ Retour à la liste'],
                ],
            ]],
        ];
    }

    /** Fermer la cagnotte → Oui (1) / Non (2). Boutons. */
    private static function confirmationFermeture(): array
    {
        return [
            'type'    => 'boutons',
            'boutons' => [
                ['id' => '1', 'titre' => '✅ Oui, fermer'],
                ['id' => '2', 'titre' => '↩️ Non, annuler'],
            ],
        ];
    }

    /**
     * Retire d'un texte de menu le bloc des options numérotées et les lignes
     * d'invite « Tapez… », pour en faire le CORPS d'un message interactif.
     *
     * Supprime, ligne par ligne :
     *   - les options en chiffre-emoji (1️⃣ 2️⃣ …) ;
     *   - la ligne de retour « #️⃣ … » ;
     *   - toute ligne contenant « Tapez … ».
     * Le reste (intro, récap, avertissements) est conservé tel quel — c'est ce
     * qui préserve le contenu dynamique. Les sauts de ligne multiples sont réduits.
     *
     * @param  string $texte  Réponse texte brute du bot.
     * @return string         Corps nettoyé (peut être vide si le texte n'était que des options).
     */
    public static function corpsSansOptions(string $texte): string
    {
        $gardees = [];
        foreach (preg_split('/\r?\n/', $texte) as $ligne) {
            $t = trim($ligne);
            // Option en chiffre-emoji (« 1️⃣ … ») ou ligne de retour « #️⃣ … ».
            if (preg_match('/^\s*[0-9]\x{FE0F}?\x{20E3}/u', $t)) {
                continue;
            }
            if (preg_match('/^\s*#\x{FE0F}?\x{20E3}/u', $t)) {
                continue;
            }
            // Ligne d'invite « Tapez le numéro… », « _Tapez *1* pour…_ », « ⚠️ Tapez… ».
            // NB : pas de \b — le « _ » de formatage WhatsApp compte comme un
            // caractère de mot et ferait échouer la frontière avant « Tapez ».
            if (preg_match('/tapez/iu', $t)) {
                continue;
            }
            $gardees[] = $ligne;
        }

        // Réduit les trous laissés par les lignes supprimées.
        return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $gardees)));
    }
}
