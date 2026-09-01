<?php

namespace App\Support;

/**
 * Calculs PURS du modèle de frais Tonji (sans DB).
 *
 * Le cotisant paie : le NET (livré au bénéficiaire) + les frais de retrait
 * éventuels (matrice config) + la commission Paynala. Décomposé en deux étapes,
 * comme dans les contrôleurs de cotisation :
 *   totalAEnvoyer = round(net × (1 + fraisRetrait))
 *   montantBrut   = ceil(totalAEnvoyer × (1 + commission))
 */
class FraisCalculator
{
    /** Total à envoyer (net + frais de retrait) — arrondi. */
    public static function totalAEnvoyer(int $net, float $fraisRetrait): int
    {
        return (int) round($net * (1 + $fraisRetrait));
    }

    /** Montant brut débité au cotisant (total à envoyer + commission) — plafond haut. */
    public static function montantBrut(int $totalAEnvoyer, float $commission): int
    {
        return (int) ceil($totalAEnvoyer * (1 + $commission));
    }

    /**
     * Taux de frais de retrait (décimal) dans la matrice config, croisant le
     * type de cotisation (cagnotte/tontine) et le type d'utilisateur
     * (particulier/association). 0 par défaut.
     */
    public static function tauxRetrait(array $matrice, string $typeCotisation, string $typeUser): float
    {
        return (float) ($matrice[$typeCotisation][$typeUser] ?? 0);
    }
}
