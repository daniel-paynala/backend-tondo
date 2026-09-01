<?php

namespace Tests\Unit;

use App\Support\PlafondResolver;
use PHPUnit\Framework\TestCase;

/**
 * Résolution du plafond total de collecte selon le type de compte + override.
 */
class PlafondResolverTest extends TestCase
{
    private array $config = [
        'plafond_cagnotte_particulier' => 2500000,
        'plafond_cagnotte_association' => 10000000,
    ];

    public function test_particulier_sans_override_utilise_le_plafond_config(): void
    {
        $this->assertSame(2500000, PlafondResolver::resoudre('particulier', null, null, $this->config));
    }

    public function test_particulier_avec_override_utilise_l_override(): void
    {
        $this->assertSame(4000000, PlafondResolver::resoudre('particulier', null, 4000000, $this->config));
    }

    public function test_association_utilise_le_plafond_de_l_orga(): void
    {
        // L'override de l'orga (déblocage) l'emporte sur le plafond association config.
        $this->assertSame(25000000, PlafondResolver::resoudre('association', 25000000, null, $this->config));
    }

    public function test_association_sans_plafond_orga_utilise_le_defaut_config(): void
    {
        $this->assertSame(10000000, PlafondResolver::resoudre('association', null, null, $this->config));
    }

    public function test_type_null_traite_comme_particulier(): void
    {
        $this->assertSame(2500000, PlafondResolver::resoudre(null, null, null, $this->config));
    }

    public function test_fallback_defauts_si_config_vide(): void
    {
        $this->assertSame(PlafondResolver::DEFAUT_PARTICULIER, PlafondResolver::resoudre('particulier', null, null, []));
        $this->assertSame(PlafondResolver::DEFAUT_ASSOCIATION, PlafondResolver::resoudre('association', null, null, []));
    }

    public function test_l_override_particulier_n_impacte_pas_une_association(): void
    {
        // Un plafond_personnalise ne s'applique jamais à un compte association.
        $this->assertSame(10000000, PlafondResolver::resoudre('association', null, 999, $this->config));
    }
}
