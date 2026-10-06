<?php

namespace Tests\Unit;

use App\Support\Taux;
use PHPUnit\Framework\TestCase;

/**
 * Les taux sont stockés en décimal et affichés en pourcentage.
 *
 * Ce test existe pour une panne silencieuse : oublier la multiplication par
 * 100 ne fait rien échouer. Ça affiche « 0,03 % » là où le client sera prélevé
 * de 3 % — un prix annoncé cent fois trop bas, sans la moindre erreur dans les
 * journaux. C'est arrivé sur trois écrans à la fois.
 */
class TauxTest extends TestCase
{
    public function test_un_taux_decimal_se_lit_en_pourcentage(): void
    {
        $this->assertSame('3 %', Taux::pourcentage(0.03));
        $this->assertSame('2 %', Taux::pourcentage(0.02));
        $this->assertSame('1 %', Taux::pourcentage(0.01));
    }

    public function test_les_decimales_utiles_sont_gardees(): void
    {
        $this->assertSame('2,5 %', Taux::pourcentage(0.025));
        $this->assertSame('1,75 %', Taux::pourcentage(0.0175));
    }

    public function test_les_zeros_inutiles_sont_retires(): void
    {
        // « 3,00 % » ferait croire à une précision qui n'existe pas.
        $this->assertSame('3 %', Taux::pourcentage(0.0300));
    }

    public function test_une_exoneration_s_affiche_zero_et_non_vide(): void
    {
        // Zéro est une exonération, pas une absence : elle se dit.
        $this->assertSame('0 %', Taux::pourcentage(0.0));
    }
}
