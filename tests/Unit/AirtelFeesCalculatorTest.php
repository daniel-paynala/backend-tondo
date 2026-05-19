<?php

namespace Tests\Unit;

use App\Services\AirtelFeesCalculator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Référentiel des 5 cas posés par Daniel le 2026-05-12, plus les bornes
 * critiques (bascule tranche 1 / tranche 2, plafond par envoi, plafond
 * journalier émetteur).
 *
 * Si un de ces tests casse, c'est que les hypothèses tarifaires Airtel ont
 * changé OU qu'une refonte du calculateur a introduit une régression. Dans
 * les deux cas il faut un handoff vers Cowork avant de modifier les valeurs
 * attendues.
 */
class AirtelFeesCalculatorTest extends TestCase
{
    private AirtelFeesCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        // Config explicite (test unitaire pur, sans bootstrap Laravel) —
        // valeurs identiques aux défauts de config/airtel.php.
        $this->calc = new AirtelFeesCalculator([
            'commission_paynala' => 0.02,
            'plafond_par_envoi' => 500_000,
            'plafond_journalier' => 2_500_000,
            'retrait' => [
                'seuil_tranche' => 166_667,
                'taux_pourcentage' => 0.03,
                'forfait' => 5_000,
            ],
        ]);
    }

    public function test_cas_1_cash_100k_un_envoi_tranche_3pct(): void
    {
        $p = $this->calc->plan(100_000);
        $this->assertSame(1, $p['nombre_envois']);
        $this->assertSame(0, $p['nombre_splits']);
        $this->assertSame(103_093, $p['total_a_envoyer']);
        $this->assertSame(3_093, $p['total_frais_airtel']);
        $this->assertSame(100_000, $p['cash_livre']);
    }

    public function test_cas_2_cash_200k_un_envoi_forfait(): void
    {
        $p = $this->calc->plan(200_000);
        $this->assertSame(1, $p['nombre_envois']);
        $this->assertSame(205_000, $p['total_a_envoyer']);
        $this->assertSame(5_000, $p['total_frais_airtel']);
    }

    public function test_cas_3_cash_300k_un_envoi_forfait(): void
    {
        $p = $this->calc->plan(300_000);
        $this->assertSame(1, $p['nombre_envois']);
        $this->assertSame(305_000, $p['total_a_envoyer']);
        $this->assertSame(5_000, $p['total_frais_airtel']);
    }

    public function test_cas_4_cash_500k_deux_envois_avec_regularisation(): void
    {
        $p = $this->calc->plan(500_000);
        $this->assertSame(2, $p['nombre_envois']);
        $this->assertSame(1, $p['nombre_splits']);
        $this->assertSame(505_155, $p['total_a_envoyer']);
        $this->assertSame(5_155, $p['total_frais_airtel']);
        $this->assertSame(500_000, $p['envois'][0]['gross']);
        $this->assertSame(5_155, $p['envois'][1]['gross']);
    }

    public function test_cas_5_cash_700k_deux_envois_forfait_x2(): void
    {
        $p = $this->calc->plan(700_000);
        $this->assertSame(2, $p['nombre_envois']);
        $this->assertSame(1, $p['nombre_splits']);
        $this->assertSame(710_000, $p['total_a_envoyer']);
        $this->assertSame(10_000, $p['total_frais_airtel']);
    }

    public function test_bascule_tranche1_tranche2_overshoot_minime(): void
    {
        // Cash 161 667 = exactement entre tranches. Tranche 1 sature à 161 666
        // cash net (gross 166 667 → fee 5 001). Tranche 2 part de gross 166 668
        // → net 161 668 (overshoot 1 FCFA). On accepte l'overshoot.
        $p = $this->calc->plan(161_667);
        $this->assertSame(166_668, $p['total_a_envoyer']);
        $this->assertSame(161_668, $p['cash_livre']);
    }

    public function test_cash_max_un_envoi_satureplafond(): void
    {
        // Cash 495 000 = exactement ce qu'un envoi saturé livre (500 000 - 5 000).
        $p = $this->calc->plan(495_000);
        $this->assertSame(1, $p['nombre_envois']);
        $this->assertSame(500_000, $p['total_a_envoyer']);
    }

    public function test_cash_minimum_100_fcfa(): void
    {
        $p = $this->calc->plan(100);
        $this->assertSame(1, $p['nombre_envois']);
        $this->assertGreaterThanOrEqual(100, $p['cash_livre']);
    }

    public function test_cash_inferieur_a_100_refuse(): void
    {
        $this->expectException(RuntimeException::class);
        $this->calc->plan(50);
    }
}
