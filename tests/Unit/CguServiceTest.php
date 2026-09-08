<?php

namespace Tests\Unit;

use App\Services\CguService;
use App\Services\TondoConfigService;
use PHPUnit\Framework\TestCase;

/**
 * Le texte des CGU doit suivre la configuration opérateur.
 *
 * C'est exactement la divergence constatée le 2026-09-08 : les CGU affirmaient
 * encore que les frais de retrait étaient à la charge du cotisant alors que la
 * matrice `frais_retrait` valait 0 partout depuis le 2026-08-31.
 *
 * Seule la partie pure est testée (`construire`), qui reçoit une config déjà
 * chargée : aucune base de données n'est nécessaire.
 */
class CguServiceTest extends TestCase
{
    private function service(): CguService
    {
        // La config n'est pas sollicitée par `construire()` : un double suffit.
        return new CguService($this->createMock(TondoConfigService::class));
    }

    /** @return array<string, mixed> */
    private function config(array $surcharges = []): array
    {
        return array_merge([
            'commission_paynala'           => 0.02,
            'plafond_par_envoi'            => 500000,
            'plafond_cagnotte_particulier' => 2500000,
            'plafond_cagnotte_association' => 10000000,
            'frais_retrait'                => [
                'cagnotte' => ['particulier' => 0, 'association' => 0],
                'tontine'  => ['particulier' => 0, 'association' => 0],
            ],
        ], $surcharges);
    }

    private function bloc(array $cfg, string $titre): string
    {
        foreach ($this->service()->construire($cfg)['blocs'] as $bloc) {
            if ($bloc['titre'] === $titre) {
                return $bloc['corps'];
            }
        }

        $this->fail("Bloc « {$titre} » absent des CGU.");
    }

    public function test_la_commission_vient_de_la_config(): void
    {
        $this->assertStringContainsString(
            'commission de 2 %',
            $this->bloc($this->config(), 'Modèle économique'),
        );

        $this->assertStringContainsString(
            'commission de 3,5 %',
            $this->bloc($this->config(['commission_paynala' => 0.035]), 'Modèle économique'),
        );
    }

    public function test_matrice_a_zero_les_frais_de_retrait_ne_sont_pas_repercutes(): void
    {
        $this->assertStringContainsString(
            'ne sont pas répercutés sur le cotisant',
            $this->bloc($this->config(), 'Modèle économique'),
        );
    }

    public function test_un_seul_couple_non_nul_suffit_a_les_repercuter(): void
    {
        $cfg = $this->config(['frais_retrait' => [
            'cagnotte' => ['particulier' => 0, 'association' => 0],
            'tontine'  => ['particulier' => 0.03, 'association' => 0],
        ]]);

        $this->assertStringContainsString(
            'sont également répercutés sur le',
            $this->bloc($cfg, 'Modèle économique'),
        );
    }

    public function test_les_plafonds_viennent_de_la_config(): void
    {
        $corps = $this->bloc($this->config(['plafond_par_envoi' => 750000]), 'Plafonds');

        $this->assertStringContainsString('750 000 FCFA', $corps);
        $this->assertStringContainsString('2 500 000 FCFA', $corps);
        $this->assertStringContainsString('10 000 000 FCFA', $corps);
    }

    public function test_la_version_change_avec_les_chiffres(): void
    {
        $avant = $this->service()->construire($this->config())['version'];
        $apres = $this->service()->construire($this->config(['commission_paynala' => 0.03]))['version'];

        $this->assertNotSame($avant, $apres);
    }
}
