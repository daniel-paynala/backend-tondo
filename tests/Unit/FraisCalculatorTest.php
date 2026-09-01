<?php

namespace Tests\Unit;

use App\Support\FraisCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Modèle de frais : total à envoyer (net + retrait) puis brut (+ commission),
 * et lecture de la matrice de frais de retrait.
 */
class FraisCalculatorTest extends TestCase
{
    public function test_total_a_envoyer_sans_frais_retrait(): void
    {
        $this->assertSame(3000, FraisCalculator::totalAEnvoyer(3000, 0.0));
    }

    public function test_total_a_envoyer_avec_frais_retrait(): void
    {
        // 3000 × 1,03 = 3090.
        $this->assertSame(3090, FraisCalculator::totalAEnvoyer(3000, 0.03));
    }

    public function test_montant_brut_commission_2pct(): void
    {
        // Cas de référence Daniel : net 3000, commission 2 % → ~3060.
        $this->assertSame(3060, FraisCalculator::montantBrut(3000, 0.02));
    }

    public function test_montant_brut_arrondi_au_superieur(): void
    {
        // 100 × 1,02 = 102 exactement.
        $this->assertSame(102, FraisCalculator::montantBrut(100, 0.02));
        // 101 × 1,02 = 103,02 → ceil = 104.
        $this->assertSame(104, FraisCalculator::montantBrut(101, 0.02));
    }

    public function test_taux_retrait_defaut_zero(): void
    {
        $this->assertSame(0.0, FraisCalculator::tauxRetrait([], 'cagnotte', 'particulier'));
    }

    public function test_taux_retrait_lu_dans_la_matrice(): void
    {
        $matrice = [
            'cagnotte' => ['particulier' => 0.0, 'association' => 0.03],
            'tontine'  => ['particulier' => 0.05, 'association' => 0.0],
        ];
        $this->assertSame(0.03, FraisCalculator::tauxRetrait($matrice, 'cagnotte', 'association'));
        $this->assertSame(0.05, FraisCalculator::tauxRetrait($matrice, 'tontine', 'particulier'));
        $this->assertSame(0.0, FraisCalculator::tauxRetrait($matrice, 'cagnotte', 'particulier'));
    }

    public function test_chaine_complete_net_vers_brut(): void
    {
        // net 5000, retrait 3 %, commission 2 % : 5000→5150→ceil(5253)=5253.
        $total = FraisCalculator::totalAEnvoyer(5000, 0.03); // 5150
        $this->assertSame(5150, $total);
        $this->assertSame(5253, FraisCalculator::montantBrut($total, 0.02));
    }
}
