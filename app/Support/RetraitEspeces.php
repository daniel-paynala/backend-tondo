<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Règles pures du retrait en espèces (sans DB, sans réseau).
 *
 * Formats et textes au même endroit, testables sans rien démarrer. Les formats
 * sont en miroir des contraintes CHECK de la migration 028.
 */
class RetraitEspeces
{
    /** Cycle de vie d'un dossier. Seul `valide` fait sortir de l'argent. */
    public const STATUTS = ['en_attente_code', 'valide', 'refuse', 'expire', 'annule'];

    /** TONJICASH + 9 caractères, comme la partie aléatoire des autres trans_id. */
    public const FORMAT_REFERENCE = '/^TONJICASH[A-Z0-9]{9}$/';

    /**
     * Référence du retrait, reprise telle quelle comme trans_id du payout.
     *
     * Str::random tire dans [A-Za-z0-9] ; en majuscules, il reste 36 symboles
     * sur 9 positions, soit 10^14 combinaisons. L'index unique en base tranche
     * le cas improbable d'une collision.
     */
    public static function genererReference(): string
    {
        return 'TONJICASH' . strtoupper(Str::random(9));
    }

    public static function referenceValide(?string $reference): bool
    {
        return $reference !== null && preg_match(self::FORMAT_REFERENCE, $reference) === 1;
    }

    /** Code d'autorisation, chiffres seulement : il se dicte au comptoir. */
    public static function genererCode(int $longueur = 6): string
    {
        return str_pad((string) random_int(0, (10 ** $longueur) - 1), $longueur, '0', STR_PAD_LEFT);
    }

    /** « 150 000 » : séparateur d'espace, lisible sur un écran de téléphone. */
    public static function formaterMontant(int $montant): string
    {
        return number_format($montant, 0, ',', ' ');
    }

    /**
     * SMS envoyé au titulaire au moment de la demande.
     *
     * Montant ET agent y figurent : sans le montant, le titulaire autoriserait
     * à l'aveugle ; sans l'agent, il ne pourrait pas vérifier qu'il est au bon
     * comptoir. La dernière phrase vise l'escroquerie la plus probable — un
     * appel qui réclame « le code que vous venez de recevoir ».
     *
     * Sans accents : un seul accent fait passer le SMS en UCS-2, et sa longueur
     * utile de 160 à 70 caractères.
     */
    public static function texteSmsDemande(
        int $montant,
        string $cagnotteReference,
        string $agentNom,
        string $agentIdentifiant,
        string $code,
        int $validiteMinutes,
    ): string {
        return 'Tonji: retrait ' . self::formaterMontant($montant) . " FCFA cagnotte {$cagnotteReference}"
            . ' chez ' . self::nomCourt($agentNom) . " ({$agentIdentifiant})."
            . " Code {$code}, valable {$validiteMinutes} min."
            . ' Ne le donnez jamais par telephone.';
    }

    /**
     * SMS de confirmation, après validation.
     *
     * Prévient le titulaire à la seconde où l'argent sort, avec une référence
     * à citer : si l'agent a validé sans remettre les espèces, le titulaire le
     * sait immédiatement, pas à la prochaine consultation de son solde.
     */
    public static function texteSmsConfirmation(
        int $montant,
        string $agentNom,
        string $agentIdentifiant,
        string $reference,
        ?string $numeroAssistance,
    ): string {
        $texte = 'Tonji: retrait ' . self::formaterMontant($montant) . ' FCFA effectue chez '
            . self::nomCourt($agentNom) . " ({$agentIdentifiant}). Ref {$reference}.";

        if ($numeroAssistance !== null && trim($numeroAssistance) !== '') {
            $texte .= ' Pas vous ? Appelez le ' . trim($numeroAssistance) . '.';
        }

        return $texte;
    }

    /** Longueur maximale du nom d'agent dans un SMS. */
    public const NOM_SMS_MAX = 20;

    /**
     * Nom d'agent raccourci pour le SMS, sans accents.
     *
     * Un nom peut compter jusqu'à 120 caractères ; en entier, il ferait passer
     * le message sur deux SMS, facturés double et parfois reçus dans le
     * désordre — le code avant le montant. On tronque le nom et jamais
     * l'identifiant : c'est lui que le titulaire confronte au comptoir.
     */
    public static function nomCourt(string $nom): string
    {
        return rtrim(mb_substr(self::sansAccents(trim($nom)), 0, self::NOM_SMS_MAX));
    }

    /** Retire les accents pour rester dans l'alphabet GSM à 160 caractères. */
    public static function sansAccents(string $texte): string
    {
        return Str::ascii($texte);
    }
}
