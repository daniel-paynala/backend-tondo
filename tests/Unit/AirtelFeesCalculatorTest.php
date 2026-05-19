<?php

namespace Tests\Unit;

use App\Services\AirtelFeesCalculator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Référentiel des cas de base (Daniel, 2026-05-12 + 2026-05-19).
 *
 * Modèle NET-based (validé 2026-05-19) :
 *   frais = round(net × taux)  → gross = net + frais
 *
 * Config utilisée : Airtel Gabon par défaut —
 *   T1 : net ≤ 166 667 FCFA → 3 %
 *   T2 : net > 166 667 FCFA → forfait 5 000 FCFA
 */
class AirtelFeesCalculatorTest extends TestCase
{
    private AirtelFeesCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new AirtelFeesCalculator([
            'commission_paynala' => 0.02,
            'plafond_par_envoi'  => 500_000,
            'plafond_journalier' => 2_500_000,
            'tranches'           => [
                ['montant_min' => 100,     'montant_max' => 166_667, 'type' => 'pourcentage', 'valeur' => 0.03],
                ['montant_min' => 166_668, 'montant_max' => null,    'type' => 'forfait',     'valeur' => 5_000],
            ],
        ]);
    }

    // ── cas Daniel 2026-05-12 + 2026-05-19 ─────────────────────────────

    public function test_cas_1_cash_100k_un_envoi_tranche_3pct(): void
    {
        // fee = round(100 000 × 0.03) = 3 000 → gross = 103 000
        $p = $this->calc->plan(100_000);
        $this->assertSame(1,       $p['nombre_envois']);
        $this->assertSame(0,       $p['nombre_splits']);
        $this->assertSame(103_000, $p['total_a_envoyer']);
        $this->assertSame(3_000,   $p['total_frais_airtel']);
        $this->assertSame(100_000, $p['cash_livre']);
    }

    public function test_cas_1b_cash_3k_un_envoi_tranche_3pct(): void
    {
        // fee = round(3 000 × 0.03) = 90 → gross = 3 090
        // Cas réel Airtel confirmé par Daniel : 3 cotisants × 1 030 = 3 090.
        $p = $this->calc->plan(3_000);
        $this->assertSame(1,     $p['nombre_envois']);
        $this->assertSame(3_090, $p['total_a_envoyer']);
        $this->assertSame(90,    $p['total_frais_airtel']);
        $this->assertSame(3_000, $p['cash_livre']);
    }

    public function test_cas_1c_cash_1k_par_cotisant(): void
    {
        // Tontine : 1 000 FCFA net par cycle, 3 participants.
        // gross = 1 030, fee = 30 → chaque cotisant paie 3 090 / 3 = 1 030.
        $p = $this->calc->plan(1_000);
        $this->assertSame(1_030, $p['total_a_envoyer']);
        $this->assertSame(30,    $p['total_frais_airtel']);
    }

    public function test_cas_2_cash_200k_un_envoi_forfait(): void
    {
        $p = $this->calc->plan(200_000);
        $this->assertSame(1,       $p['nombre_envois']);
        $this->assertSame(205_000, $p['total_a_envoyer']);
        $this->assertSame(5_000,   $p['total_frais_airtel']);
        $this->assertSame(200_000, $p['cash_livre']);
    }

    public function test_cas_3_cash_300k_un_envoi_forfait(): void
    {
        $p = $this->calc->plan(300_000);
        $this->assertSame(1,       $p['nombre_envois']);
        $this->assertSame(305_000, $p['total_a_envoyer']);
        $this->assertSame(5_000,   $p['total_frais_airtel']);
    }

    public function test_cas_4_cash_500k_un_envoi_forfait(): void
    {
        // 500 000 ≤ plafond → un seul envoi, fee forfait 5 000.
        $p = $this->calc->plan(500_000);
        $this->assertSame(1,       $p['nombre_envois']);
        $this->assertSame(0,       $p['nombre_splits']);
        $this->assertSame(505_000, $p['total_a_envoyer']);
        $this->assertSame(5_000,   $p['total_frais_airtel']);
        $this->assertSame(500_000, $p['cash_livre']);
    }

    public function test_cas_5_cash_700k_deux_envois_forfait_x2(): void
    {
        // 700 000 > plafond (500 000) → 2 envois, 2 × 5 000 = 10 000 frais.
        $p = $this->calc->plan(700_000);
        $this->assertSame(2,       $p['nombre_envois']);
        $this->assertSame(1,       $p['nombre_splits']);
        $this->assertSame(710_000, $p['total_a_envoyer']);
        $this->assertSame(10_000,  $p['total_frais_airtel']);
        $this->assertSame(700_000, $p['cash_livre']);
    }

    // ── bornes critiques ────────────────────────────────────────────────

    public function test_bascule_tranche1_tranche2(): void
    {
        // net = 166 667 : dernier palier T1 → fee = round(166 667 × 0.03) = round(5 000.01) = 5 000.
        $p = $this->calc->plan(166_667);
        $this->assertSame(1,       $p['nombre_envois']);
        $this->assertSame(171_667, $p['total_a_envoyer']);
        $this->assertSame(5_000,   $p['total_frais_airtel']);
        $this->assertSame(166_667, $p['cash_livre']);

        // net = 166 668 : premier palier T2 → forfait 5 000.
        $p2 = $this->calc->plan(166_668);
        $this->assertSame(171_668, $p2['total_a_envoyer']);
        $this->assertSame(5_000,   $p2['total_frais_airtel']);
    }

    public function test_cash_max_un_envoi_sature_plafond(): void
    {
        // net = 500 000 = plafond → toujours un envoi.
        $p = $this->calc->plan(500_000);
        $this->assertSame(1,       $p['nombre_envois']);
        $this->assertSame(505_000, $p['total_a_envoyer']);
    }

    public function test_cash_minimum_100_fcfa(): void
    {
        $p = $this->calc->plan(100);
        $this->assertSame(1,   $p['nombre_envois']);
        $this->assertSame(100, $p['cash_livre']);
    }

    public function test_cash_inferieur_a_100_refuse(): void
    {
        $this->expectException(RuntimeException::class);
        $this->calc->plan(50);
    }

    public function test_sans_tranches_frais_nuls(): void
    {
        $calc = new AirtelFeesCalculator([
            'commission_paynala' => 0.0,
            'plafond_par_envoi'  => 500_000,
            'plafond_journalier' => 2_500_000,
            'tranches'           => [],
        ]);
        $p = $calc->plan(200_000);
        $this->assertSame(200_000, $p['total_a_envoyer']);
        $this->assertSame(0,       $p['total_frais_airtel']);
        $this->assertSame(200_000, $p['cash_livre']);
    }

    public function test_forfait_unique_sans_seuil(): void
    {
        $calc = new AirtelFeesCalculator([
            'commission_paynala' => 0.0,
            'plafond_par_envoi'  => 500_000,
            'plafond_journalier' => 2_500_000,
            'tranches'           => [
                ['montant_min' => null, 'montant_max' => null, 'type' => 'forfait', 'valeur' => 3_000],
            ],
        ]);
        $p = $calc->plan(200_000);
        $this->assertSame(203_000, $p['total_a_envoyer']);
        $this->assertSame(3_000,   $p['total_frais_airtel']);
    }
}
