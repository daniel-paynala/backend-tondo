<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Règles des agents de retrait en espèces (sans DB).
 *
 * Tout ce qui décide ici est pur : sigles, identifiant, PIN, clé d'API. Les
 * lectures et écritures en base restent dans les contrôleurs. Les formats
 * sont en miroir des contraintes CHECK de la migration 028 — la base refuse
 * ce que ce code laisserait passer, et inversement, de sorte qu'un oubli d'un
 * côté se voit tout de suite.
 */
class RetraitAgents
{
    /**
     * Trois lettres majuscules, exactement.
     *
     * L'identifiant d'un agent accole le sigle du partenaire et celui du
     * support. Seule une longueur fixe permet de les séparer sans ambiguïté :
     * avec des longueurs libres, « EC » + « KTPE » et « ECK » + « TPE »
     * donneraient le même identifiant.
     */
    public const FORMAT_SIGLE = '/^[A-Z]{3}$/';

    /** « ECKTPE020 » : deux sigles, puis un numéro sur au moins 3 chiffres. */
    public const FORMAT_IDENTIFIANT = '/^[A-Z]{6}[0-9]{3,}$/';

    /** Largeur minimale du numéro : « 020 » plutôt que « 20 ». */
    public const LARGEUR_NUMERO = 3;

    public const STATUTS = ['actif', 'suspendu'];

    /**
     * Échecs de PIN tolérés avant verrouillage.
     *
     * Quatre chiffres ne font que 10 000 combinaisons. Sans verrouillage, un
     * script en viendrait à bout en quelques minutes ; avec, il lui reste cinq
     * essais — une chance sur deux mille.
     */
    public const MAX_TENTATIVES_PIN = 5;

    // ── Sigles ───────────────────────────────────────────────────────────────

    /** Majuscules et sans espaces : « eck » saisi dans un formulaire devient « ECK ». */
    public static function normaliserSigle(?string $sigle): string
    {
        return strtoupper(trim((string) $sigle));
    }

    public static function sigleValide(?string $sigle): bool
    {
        return $sigle !== null && preg_match(self::FORMAT_SIGLE, $sigle) === 1;
    }

    // ── Identifiant ──────────────────────────────────────────────────────────

    /**
     * Compose l'identifiant d'un agent : « ECK » + « TPE » + 20 → « ECKTPE020 ».
     *
     * Appelé UNE fois, à la création. L'identifiant est ensuite figé en base :
     * si le sigle du partenaire change, les agents existants gardent le leur,
     * qui figure dans des SMS déjà envoyés et dans les journaux.
     *
     * @throws InvalidArgumentException sur un sigle mal formé ou un numéro nul.
     */
    public static function composerIdentifiant(string $siglePartenaire, string $sigleSupport, int $numero): string
    {
        if (! self::sigleValide($siglePartenaire) || ! self::sigleValide($sigleSupport)) {
            throw new InvalidArgumentException('Sigle invalide : trois lettres majuscules attendues.');
        }
        if ($numero < 1) {
            throw new InvalidArgumentException('Le numéro d\'agent commence à 1.');
        }

        return $siglePartenaire
            . $sigleSupport
            . str_pad((string) $numero, self::LARGEUR_NUMERO, '0', STR_PAD_LEFT);
    }

    /** Majuscules et sans espaces, pour tolérer une saisie « ecktpe020 » au terminal. */
    public static function normaliserIdentifiant(?string $identifiant): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $identifiant) ?? '');
    }

    public static function identifiantValide(?string $identifiant): bool
    {
        return $identifiant !== null && preg_match(self::FORMAT_IDENTIFIANT, $identifiant) === 1;
    }

    // ── PIN ──────────────────────────────────────────────────────────────────

    public static function pinFormatValide(?string $pin): bool
    {
        return $pin !== null && preg_match('/^[0-9]{4}$/', $pin) === 1;
    }

    /**
     * Raison pour laquelle un PIN est refusé, ou null s'il est acceptable.
     *
     * Les PIN écartés sont ceux qu'on essaie en premier sur un compte dont on
     * ignore le code : chiffre répété (0000), suite (1234, 9876), motifs
     * répétés (1212, 1122) et en miroir (1001, 2112). C'est une poignée de
     * combinaisons sur dix mille, mais ce sont celles que tout le monde tente.
     */
    public static function motifRefusPin(?string $pin): ?string
    {
        if (! self::pinFormatValide($pin)) {
            return 'Le PIN doit comporter exactement 4 chiffres.';
        }

        [$a, $b, $c, $d] = array_map('intval', str_split($pin));

        if ($a === $b && $b === $c && $c === $d) {
            return 'Le PIN ne peut pas répéter le même chiffre.';
        }
        if (($b === $a + 1 && $c === $b + 1 && $d === $c + 1)
            || ($b === $a - 1 && $c === $b - 1 && $d === $c - 1)) {
            return 'Le PIN ne peut pas être une suite de chiffres.';
        }
        if ($a === $c && $b === $d) {
            return 'Le PIN ne peut pas répéter un même motif (ex. 1212).';
        }
        if ($a === $b && $c === $d) {
            return 'Le PIN ne peut pas être formé de deux paires (ex. 1122).';
        }
        if ($a === $d && $b === $c) {
            return 'Le PIN ne peut pas être symétrique (ex. 1001).';
        }

        return null;
    }

    /**
     * Tire un PIN initial au hasard, jamais trivial.
     *
     * Affiché une seule fois à l'admin, qui le transmet à l'agent ; l'agent le
     * remplace à sa première connexion. Aléatoire plutôt qu'un « 1001 »
     * commun à tous : les identifiants se suivent, et un PIN connu de tous
     * ouvrirait chaque agent pas encore activé à quiconque devine son numéro.
     */
    public static function genererPin(): string
    {
        do {
            $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (self::motifRefusPin($pin) !== null);

        return $pin;
    }

    // ── Clé d'API du partenaire ──────────────────────────────────────────────

    /**
     * Fabrique la clé d'API d'un partenaire et son empreinte.
     *
     * @return array{cle:string, hash:string, apercu:string}
     *         `cle` n'est montrée qu'UNE fois : seule l'empreinte est conservée.
     */
    public static function genererCleApi(string $siglePartenaire): array
    {
        // Le sigle préfixe la clé : en cas de fuite dans un journal, on sait
        // aussitôt quel partenaire révoquer, sans comparer des empreintes.
        $secret = bin2hex(random_bytes(24));
        $cle    = 'tonji_pr_' . strtolower($siglePartenaire) . '_' . $secret;

        return [
            'cle'    => $cle,
            // SHA-256 et non bcrypt : il faut RETROUVER le partenaire à partir
            // de la clé présentée. La clé est un aléa de 24 octets, pas un mot
            // de passe humain : aucun dictionnaire à lui opposer.
            'hash'   => hash('sha256', $cle),
            'apercu' => substr($secret, -4),
        ];
    }

    public static function empreinteCle(string $cle): string
    {
        return hash('sha256', $cle);
    }
}
