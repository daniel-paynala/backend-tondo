<?php

namespace Tests\Unit;

use App\Services\PaynalaPaymentService;
use Tests\TestCase;

/**
 * Le mode de décaissement décide si l'argent part en B2B ou en B2C. Se tromper
 * ne renvoie pas une erreur franche : Airtel répond « Transaction Ambiguous »,
 * la cagnotte est débitée et rien n'arrive chez le bénéficiaire.
 */
class ModeDisburseTest extends TestCase
{
    public function test_un_compte_professionnel_part_en_b2b(): void
    {
        $this->assertSame('B2B', PaynalaPaymentService::modeDisburse('entreprise'));
    }

    public function test_un_compte_personnel_part_en_b2c(): void
    {
        $this->assertSame('B2C', PaynalaPaymentService::modeDisburse('particulier'));
    }

    public function test_un_type_inconnu_retombe_sur_le_mode_particulier(): void
    {
        // Un B2C vers un compte professionnel passe ; l'inverse échoue. Le
        // doute se tranche donc toujours du même côté.
        $this->assertSame('B2C', PaynalaPaymentService::modeDisburse(null));
        $this->assertSame('B2C', PaynalaPaymentService::modeDisburse('association'));
    }

    public function test_les_modes_se_changent_par_configuration(): void
    {
        config(['services.paynala.routage_disburse' => [
            'entreprise'  => 'MERCHANT',
            'particulier' => 'SUBSCRIBER',
        ]]);

        $this->assertSame('MERCHANT', PaynalaPaymentService::modeDisburse('entreprise'));
        $this->assertSame('SUBSCRIBER', PaynalaPaymentService::modeDisburse('particulier'));
        $this->assertSame('SUBSCRIBER', PaynalaPaymentService::modeDisburse(null));
    }
}
