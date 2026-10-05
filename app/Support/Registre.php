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
     * Alphabet des références de transaction.
     *
     * Majuscules et chiffres **sans `I`, `L`, `O` ni `U`** : ce sont les quatre
     * glyphes qu'on confond à la lecture — `I`/`1`, `L`/`1`, `O`/`0` — et `U`
     * part avec eux pour qu'aucun mot ne se forme par accident. Une référence
     * dictée au téléphone ou recopiée depuis un SMS cesse d'être un piège.
     *
     * 32 symboles sur 9 ou 10 positions : 3,5 × 10¹³ à 1,1 × 10¹⁵ combinaisons.
     * L'index unique sur `trans_id` tranche le cas improbable d'une collision.
     *
     * Les références déjà émises gardent leur alphabet d'origine : elles
     * restent valides, seules les nouvelles changent.
     */
    public const ALPHABET_REFERENCE = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Longueur du suffixe quand la clé n'en déclare pas d'autre. */
    public const LONGUEUR_SUFFIXE = 9;

    /**
     * Longueurs qui s'écartent de la valeur par défaut.
     *
     * L'écart est historique et non un choix : `payin` a toujours tiré dix
     * caractères. On le conserve parce que des validations ailleurs comptent
     * les positions — {@see \App\Support\RetraitEspeces::FORMAT_REFERENCE}
     * en est un exemple pour `retrait_especes`.
     */
    public const LONGUEURS_SUFFIXE = [
        'payin' => 10,
    ];

    /**
     * Fabrique une référence de transaction neuve.
     *
     * Prend la **clé** du registre et non le préfixe : c'est ce qui empêche un
     * appelant de réécrire « TONJIPAYOUT » à la main, et c'est exactement ce
     * qui avait laissé le bot WhatsApp émettre « TONDOPAYIN » pendant des mois.
     *
     * Le tirage passe par `random_int`, générateur cryptographique, et non par
     * un aléa de confort : le `trans_id` est l'adresse de la page publique de
     * reçu `/recu/{transId}`. Une référence devinable exposerait le reçu de
     * quelqu'un d'autre.
     *
     * @throws \InvalidArgumentException si la clé n'est pas déclarée.
     */
    public static function nouvelleReference(string $cle): string
    {
        $prefixe = self::PREFIXES[$cle] ?? null;
        if ($prefixe === null) {
            throw new \InvalidArgumentException(
                "Clé de préfixe inconnue : {$cle}. Déclarez-la dans Registre::PREFIXES."
            );
        }

        $longueur = self::LONGUEURS_SUFFIXE[$cle] ?? self::LONGUEUR_SUFFIXE;
        $dernier  = strlen(self::ALPHABET_REFERENCE) - 1;

        $suffixe = '';
        for ($i = 0; $i < $longueur; $i++) {
            $suffixe .= self::ALPHABET_REFERENCE[random_int(0, $dernier)];
        }

        return $prefixe . $suffixe;
    }

    /**
     * Code court de chaque préfixe, pour la référence communiquée aux gens.
     *
     * `TONJIMERCHANTBPU2JID53` devient `TM-BPU2JID53` : 22 caractères à 12,
     * lisibles dans un SMS et recopiables sans erreur. La deuxième lettre dit
     * le SENS et non le mot, parce que « payin » et « payout » s'abrègent
     * tous les deux en « TP » — `TI` pour ce qui entre, `TO` pour ce qui sort.
     *
     * **La réécriture est sans perte** : le code court se redéveloppe en
     * préfixe long de façon déterministe. La référence courte n'est donc pas
     * un second identifiant — c'est un format d'affichage. Rien n'est stocké,
     * rien ne peut diverger, et une recherche se fait en redéveloppant puis en
     * interrogeant l'index existant sur `trans_id`.
     *
     * Les préfixes bannis ont leur code eux aussi : ils survivent dans des
     * lignes de production, et une réconciliation qui ne les couvrirait pas
     * laisserait l'historique de côté. Leur initiale `D` rappelle « TONDO ».
     *
     * Les anciens préfixes à tirets (`TONDO-WA-`, `TONJI-COTISATION-`…) n'y
     * figurent pas : leur forme est trop éloignée pour qu'un code de deux
     * lettres reste parlant. {@see court()} rend alors null et l'appelant
     * affiche la référence longue, qui reste parfaitement valide.
     */
    public const CODES_COURTS = [
        'TONJIPAYIN'        => 'TI', // entrée
        'TONJIPAYOUT'       => 'TO', // sortie
        'TONJIAUTO'         => 'TA',
        'TONJISUPPR'        => 'TS',
        'TONJIDISBURSEMENT' => 'TD',
        'TONJICASH'         => 'TC',
        'TONJIMERCHANT'     => 'TM',
        'TONDOPAYIN'        => 'DI',
        'TONDOPAYOUT'       => 'DO',
        'TONDOAUTO'         => 'DA',
        'TONDOSUPPR'        => 'DS',
        'TONDODISBURSEMENT' => 'DD',
    ];

    /**
     * Référence courte d'une transaction, ou null si son préfixe est inconnu.
     *
     * Null est un refus volontaire : inventer un code pour un préfixe non
     * déclaré donnerait une référence qu'on ne saurait pas redévelopper.
     * L'appelant affiche alors le `trans_id` complet.
     */
    public static function court(string $transId): ?string
    {
        foreach (self::prefixesParLongueur() as $prefixe => $code) {
            if (str_starts_with($transId, $prefixe)) {
                return $code . '-' . substr($transId, strlen($prefixe));
            }
        }

        return null;
    }

    /**
     * Redéveloppe une référence courte en `trans_id`, ou null si elle est
     * malformée ou porte un code inconnu.
     *
     * Le découpage se fait sur le PREMIER tiret seulement, et tout est remis
     * en majuscules — la casse d'une référence recopiée ne doit pas décider
     * du succès d'une recherche.
     */
    public static function long(string $court): ?string
    {
        $tiret = strpos($court, '-');
        if ($tiret === false) {
            return null;
        }

        // Tout est remis en majuscules, suffixe compris : les `trans_id` sont
        // stockés ainsi, et une référence recopiée d'un SMS arrive souvent en
        // minuscules. Sans ça, la recherche du portail ne trouverait rien.
        $code    = strtoupper(substr($court, 0, $tiret));
        $suffixe = strtoupper(substr($court, $tiret + 1));
        if ($suffixe === '') {
            return null;
        }

        $prefixe = array_search($code, self::CODES_COURTS, strict: true);

        return $prefixe === false ? null : $prefixe . $suffixe;
    }

    /**
     * Préfixes triés du plus long au plus court.
     *
     * Aucun préfixe déclaré n'est aujourd'hui le début d'un autre, mais ce tri
     * rend l'ordre de déclaration sans conséquence : le jour où l'on ajoutera
     * un `TONJIPAY`, il ne volera pas les `TONJIPAYIN`.
     *
     * @return array<string, string>
     */
    private static function prefixesParLongueur(): array
    {
        $prefixes = self::CODES_COURTS;
        uksort($prefixes, static fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $prefixes;
    }

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
