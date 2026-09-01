<?php

namespace App\Support;

/**
 * Résolution PURE du plafond total de collecte d'une cagnotte (sans accès DB).
 *
 * Règle : l'override du compte l'emporte sur le plafond global du type de compte.
 *  – association → `org.plafond_fcfa` (déblocage), sinon plafond association config ;
 *  – particulier → `users.plafond_personnalise`, sinon plafond particulier config.
 *
 * La lecture DB (org, user) reste dans les contrôleurs ; ici, uniquement la
 * décision — 100 % testable.
 */
class PlafondResolver
{
    public const DEFAUT_PARTICULIER = 2500000;
    public const DEFAUT_ASSOCIATION = 10000000;

    /**
     * @param  ?string $typeCompte           'association' | 'particulier' | null
     * @param  ?int    $orgPlafond           plafond_fcfa de l'orga (assoc), ou null
     * @param  ?int    $plafondPersonnalise  override du compte particulier, ou null
     * @param  array   $config               config projet (plafonds globaux)
     */
    public static function resoudre(
        ?string $typeCompte,
        ?int $orgPlafond,
        ?int $plafondPersonnalise,
        array $config,
    ): int {
        if ($typeCompte === 'association') {
            $defaut = (int) ($config['plafond_cagnotte_association'] ?? self::DEFAUT_ASSOCIATION);

            return (int) ($orgPlafond ?? $defaut);
        }

        $defaut = (int) ($config['plafond_cagnotte_particulier'] ?? self::DEFAUT_PARTICULIER);

        return (int) ($plafondPersonnalise ?? $defaut);
    }
}
