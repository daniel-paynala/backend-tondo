<?php

namespace App\Support;

/**
 * Mise en forme d'un taux stocké en décimal.
 *
 * **Les taux sont stockés en décimal** : `0.03` vaut 3 %. C'est vrai de
 * `project_config.frais_marchand`, de `marchands.frais_taux` et de la matrice
 * `frais_retrait`. Le dashboard divise par 100 à l'enregistrement et multiplie
 * à l'affichage.
 *
 * Cette classe existe parce que l'oubli de la conversion ne casse rien : il
 * affiche « 0,03 % » là où le client sera prélevé de 3 %. Rien ne plante, rien
 * n'échoue — on annonce simplement un prix cent fois trop bas. Un seul
 * formateur, utilisé partout où un taux se montre.
 */
class Taux
{
    /** `0.02` → « 2 % ». Deux décimales au plus, sans zéros inutiles. */
    public static function pourcentage(float $taux): string
    {
        $valeur = rtrim(rtrim(number_format($taux * 100, 2, ',', ' '), '0'), ',');

        return $valeur . ' %';
    }
}
