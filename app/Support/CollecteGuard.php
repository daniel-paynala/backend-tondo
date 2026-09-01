<?php

namespace App\Support;

/**
 * Garde de collecte PURE : décide si une cotisation est autorisée (sans DB).
 *
 * Ferme deux trous que le gate mobile ne couvrait pas :
 *  – une cagnotte PUBLIQUE ne collecte qu'une fois VALIDÉE par la modération ;
 *  – une ASSOCIATION ne collecte que si son dossier est APPROUVÉ (bloque en
 *    attente / rejetée / suspendue → la suspension coupe la collecte).
 *
 * La lecture DB (gérant, orga) reste dans les contrôleurs ; ici, uniquement la
 * décision.
 */
class CollecteGuard
{
    /**
     * @return ?string  message de blocage, ou null si la collecte est autorisée.
     */
    public static function bloquee(
        ?string $visibilite,
        ?string $statutValidation,
        ?string $typeCompteGerant,
        ?string $orgStatut,
    ): ?string {
        // Cagnotte publique : doit être approuvée par la modération.
        if ($visibilite === 'public' && $statutValidation !== 'approuvee') {
            return 'Cette cagnotte publique est en cours de validation.';
        }

        // Association gérante : dossier approuvé requis (bloque en_attente / rejete / suspendu).
        if ($typeCompteGerant === 'association' && $orgStatut !== 'approuve') {
            return 'Collecte indisponible : l\'association n\'est pas active.';
        }

        return null;
    }
}
