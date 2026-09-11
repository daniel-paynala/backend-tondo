<?php

namespace App\Support;

/**
 * Vocabulaire des agents de retrait en espèces (sans DB).
 *
 * Fait foi côté code, en miroir des contraintes CHECK de la migration 028.
 * Les deux doivent être modifiés ensemble : la base refuse une valeur absente
 * de sa contrainte, et le code refuse une valeur absente d'ici — l'oubli de
 * l'un des deux se voit donc immédiatement, au lieu de laisser passer une
 * valeur qu'une moitié du système ignore.
 */
class TypesAgent
{
    /**
     * Nature du point, qui détermine par quel moyen il joint l'API.
     *
     * `tpe`     : terminal de paiement chez un commerçant. Appelle l'API avec
     *             sa propre clé, sans intervention humaine côté serveur.
     * `guichet` : comptoir partenaire sans terminal — l'opérateur passe par
     *             une page web ou l'application.
     *
     * La liste est volontairement courte : on n'invente pas de catégories
     * commerciales avant que le partenariat ne les ait fait apparaître.
     */
    public const TYPES = ['tpe', 'guichet'];

    /**
     * `suspendu` coupe l'accès à l'appel SUIVANT.
     *
     * Un agent remet des espèces : le jour où un terminal est compromis ou un
     * partenaire en litige, il faut pouvoir l'arrêter sans déploiement ni
     * purge de cache.
     */
    public const STATUTS = ['actif', 'suspendu'];

    /**
     * Format du code public : une lettre, un tiret, quatre chiffres.
     *
     * Ce code est lu à voix haute au comptoir et confronté au SMS reçu par le
     * bénéficiaire. D'où le format court et la partie numérique : mêler
     * lettres et chiffres exposerait aux confusions O/0 et I/1 au moment
     * précis où quelqu'un vérifie qu'il est au bon endroit.
     */
    public const FORMAT_CODE = '/^[A-Z]-[0-9]{4}$/';

    /** Lettres admises en préfixe — I, O et Q écartées pour la même raison. */
    private const LETTRES = 'ABCDEFGHJKLMNPRSTUVWXYZ';

    public static function typeValide(?string $type): bool
    {
        return $type !== null && in_array($type, self::TYPES, true);
    }

    public static function statutValide(?string $statut): bool
    {
        return $statut !== null && in_array($statut, self::STATUTS, true);
    }

    public static function codeValide(?string $code): bool
    {
        return $code !== null && preg_match(self::FORMAT_CODE, $code) === 1;
    }

    /**
     * Tire un code au hasard, au bon format.
     *
     * Tiré et non incrémenté : une numérotation séquentielle publierait le
     * nombre d'agents et l'ordre des recrutements. L'appelant vérifie
     * l'unicité en base — 23 × 10 000 combinaisons laissent de la marge, mais
     * une collision reste possible et doit être traitée par une nouvelle
     * tentative, jamais ignorée.
     */
    public static function genererCode(): string
    {
        $lettre = self::LETTRES[random_int(0, strlen(self::LETTRES) - 1)];

        return $lettre . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Fabrique une clé d'API et son empreinte.
     *
     * @return array{cle:string, hash:string, apercu:string}
     *         `cle` n'est montrée qu'UNE fois, à la création : rien ne permet
     *         de la retrouver ensuite, seule son empreinte est conservée.
     */
    public static function genererCleApi(string $code): array
    {
        // Le code préfixe la clé : en cas de fuite dans un journal, on sait
        // immédiatement quel agent révoquer, sans avoir à comparer des
        // empreintes.
        $secret = bin2hex(random_bytes(24));
        $cle    = 'tonji_ag_' . strtolower(str_replace('-', '', $code)) . '_' . $secret;

        return [
            'cle'    => $cle,
            // SHA-256 et non bcrypt : il faut pouvoir RETROUVER l'agent à
            // partir de la clé présentée, ce qu'un hachage salé interdit. La
            // clé étant un aléa de 24 octets et non un mot de passe humain,
            // il n'existe pas de dictionnaire à lui opposer.
            'hash'   => hash('sha256', $cle),
            'apercu' => substr($secret, -4),
        ];
    }

    /** Empreinte d'une clé présentée, pour la confronter à celle stockée. */
    public static function empreinte(string $cle): string
    {
        return hash('sha256', $cle);
    }
}
