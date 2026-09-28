<?php

namespace App\Support;

/**
 * Registre des constantes structurantes de Tonji.
 *
 * Source unique pour ce qui doit rester cohérent d'un bout à l'autre du
 * produit : préfixes de transaction, drapeaux de fonctionnalité, tâches
 * planifiées, réglages indispensables.
 *
 * Il existe parce qu'un préfixe écrit à la main dans quatre fichiers a survécu
 * des mois en production — le bot WhatsApp émettait encore « TONDOPAYIN » vers
 * l'opérateur alors que le reste du produit était passé à « TONJI ». Rien ne
 * comparait ces quatre endroits.
 *
 * Règle : toute nouvelle constante de ce type se déclare ICI, et le code la
 * lit au lieu de l'écrire. {@see \App\Console\Commands\AuditCommand} refuse
 * les valeurs qui n'y figurent pas.
 */
final class Registre
{
    /** Constructeur privé : registre statique, pas d'instance. */
    private function __construct() {}

    /**
     * Préfixes de référence de transaction autorisés, par usage.
     *
     * La valeur est le préfixe ; la clé dit où il est émis, pour qu'une
     * relecture du registre suffise à savoir qui produit quoi.
     */
    public const PREFIXES = [
        'payin'                  => 'TONJIPAYIN',        // encaissements, tous canaux
        'payout_manuel'          => 'TONJIPAYOUT',       // transfert demandé par le gérant
        'payout_auto'            => 'TONJIAUTO',         // cron de 18 h
        'payout_suppression'     => 'TONJISUPPR',        // rapatriement à la suppression d'un compte
        'reference_decaissement' => 'TONJIDISBURSEMENT', // référence transmise à Paynala
        'retrait_especes'        => 'TONJICASH',         // retrait au comptoir (recette)
        'payout_marchand'        => 'TONJIMERCHANT',     // paiement d'un marchand (recette)
    ];

    /**
     * Préfixes bannis : ils ont existé en production et ne doivent plus être
     * émis. Les lignes déjà en base les conservent, c'est voulu.
     */
    public const PREFIXES_BANNIS = [
        'TONDOPAYIN',
        'TONDOPAYOUT',
        'TONDODISBURSEMENT',
        'TONDOAUTO',
        'TONDOSUPPR',
        'TONDO-WA-',
        'TONDO-TONTINE-',
        'TONDO-AUTO-',
        'TONDO-SUPPR-',
        'TONDO-COTISATION-',
        'TONJI-COTISATION-',
        'TONJI-TONTINE-',
    ];

    /**
     * Drapeaux de fonctionnalité qui doivent porter la même valeur sur les
     * trois canaux. Un drapeau ouvert d'un côté et fermé de l'autre donne une
     * application qui propose ce que le serveur refuse.
     *
     * Les chemins sont relatifs à la racine du dépôt backend.
     */
    public const DRAPEAUX = [
        'tontines' => [
            'backend' => ['config' => 'tondo.tontines_actives'],
            'mobile'  => [
                'fichier' => '../mobile/lib/core/config/feature_flags.dart',
                'motif'   => '/kTontinesActives\s*=\s*(true|false)/',
            ],
            'web' => [
                'fichier' => '../tondo-web/src/lib/featureFlags.ts',
                'motif'   => '/TONTINES_ACTIVES\s*=\s*(true|false)/',
            ],
        ],
    ];

    /**
     * Tâches planifiées attendues : classe de commande => cadence lisible.
     *
     * Sert dans les deux sens — une tâche retirée du planificateur sans être
     * retirée d'ici lève une alerte, et une tâche ajoutée sans être déclarée
     * aussi.
     */
    public const TACHES = [
        'tontines:traiter-retraits'    => 'chaque jour à 20:00',
        'cotisations:reversements-auto' => 'chaque jour à 18:00',
        'tontines:rappels'             => 'chaque jour à 09:00',
        'tondo:verifier-paiements'     => 'toutes les 5 secondes',
        'tonji:reconcilier-payins'     => 'toutes les 5 minutes',
        'tondo:clean-receipts'         => 'chaque jour à 02:00',
        'tonji:resume-quotidien'       => 'chaque jour à 20:00',
        'tonji:agreger-evenements'     => 'chaque jour à 02:00',
        'tonji:sante'                  => 'chaque jour à 07:00',
    ];

    /**
     * Silence toléré pour chaque tâche, en secondes, avant de parler de panne.
     *
     * Une tâche quotidienne a droit à 26 heures — le temps d'un décalage et
     * d'un redémarrage — tandis que celle qui tourne toutes les cinq secondes
     * est considérée en panne après cinq minutes de silence.
     */
    public const RETARD_TOLERE = [
        'tontines:traiter-retraits'    => 93600,  // 26 h
        'cotisations:reversements-auto' => 93600, // 26 h
        'tontines:rappels'             => 93600,  // 26 h
        'tondo:verifier-paiements'     => 300,    // 5 min
        'tonji:reconcilier-payins'     => 900,    // 15 min
        'tondo:clean-receipts'         => 93600,  // 26 h
        'tonji:resume-quotidien'       => 93600,  // 26 h
        'tonji:agreger-evenements'     => 93600,  // 26 h
        'tonji:sante'                  => 93600,  // 26 h
    ];

    /**
     * Réglages sans lesquels une fonction entière tombe en silence, avec la
     * raison — c'est elle qui compte quand l'alerte apparaît à 3 h du matin.
     */
    public const REGLAGES_CRITIQUES = [
        'services.mailgun.secret' => 'Sans clé Mailgun, les invitations admin et les alertes ne partent pas.',
        'services.paynala.client_id' => 'Sans identifiants Paynala, aucun encaissement ni décaissement.',
        'services.paynala.operator_key' => 'Sans clé opérateur, les décaissements sont refusés.',
        'services.otp.driver' => 'Sans pilote OTP, personne ne peut se connecter.',
        'project.table_prefix' => 'Sans préfixe de tables, le backend lit le mauvais projet.',
    ];

    /**
     * Réglages dont la valeur par défaut est dangereuse en production : le
     * code démarre sans broncher, mais le service est faux.
     */
    public const REGLAGES_SUSPECTS = [
        'services.admin_dashboard_url' => [
            'interdit' => 'https://api.tonji.ga',
            'raison'   => 'Le lien des e-mails d\'invitation mènerait à l\'API et non au dashboard.',
        ],
        'mail.default' => [
            'interdit' => 'log',
            'raison'   => 'Les alertes des crons seraient écrites dans un fichier au lieu d\'être envoyées.',
        ],
    ];

    /**
     * Colonnes indispensables au fonctionnement courant, vérifiées contre la
     * base réellement connectée : c'est ce qui révèle un script SQL jamais
     * joué en production.
     *
     * Suffixe de table (sans préfixe de projet) => colonnes attendues.
     */
    public const SCHEMA_ATTENDU = [
        'payin'     => ['trans_id', 'canal', 'montant_net', 'statut'],
        'payout'    => ['trans_id', 'numero_tel', 'statut'],
        'cagnottes' => ['reference', 'numero_retrait', 'reversement_auto', 'statut_validation'],
        'paiements' => ['trans_id', 'canal', 'commentaire', 'actif'],
        'taches'    => ['commande', 'derniere_execution', 'statut'],
    ];

    /** Fichiers scannés par l'audit des préfixes. */
    public const DOSSIERS_SCANNES = ['app', 'routes', 'config'];

    /**
     * Fichiers exclus du scan : le registre lui-même et l'audit citent
     * forcément les préfixes qu'ils contrôlent.
     */
    public const FICHIERS_EXCLUS = [
        'app/Support/Registre.php',
        'app/Console/Commands/AuditCommand.php',
    ];

    /** @return list<string> Préfixes autorisés, sans doublon. */
    public static function prefixesAutorises(): array
    {
        return array_values(array_unique(self::PREFIXES));
    }
}
