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
            'frais_marchand'               => 0.03,
            'frais_retrait'                => [
                'cagnotte' => ['particulier' => 0, 'association' => 0],
                'tontine'  => ['particulier' => 0, 'association' => 0],
            ],
            'tranches'                     => [
                ['type' => 'pourcentage', 'valeur' => 0.03, 'montant_min' => 1, 'montant_max' => 166667],
                ['type' => 'forfait',     'valeur' => 5000, 'montant_min' => 166668, 'montant_max' => 500000],
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

    public function test_le_taux_de_commission_sur_cotisation_n_est_jamais_cite(): void
    {
        // RÈGLE 4-bis tient toujours POUR LA COTISATION : le texte dit qui
        // supporte les frais, jamais combien ils valent. Daniel a en revanche
        // demandé que le taux du paiement marchand, lui, soit annoncé — c'est
        // le créateur de la collecte qui le lit, pas le cotisant au moment de
        // payer.
        $corps = $this->bloc($this->config(['commission_paynala' => 0.035]), 'Frais');

        $this->assertStringNotContainsString('3,5 %', $corps);
    }

    public function test_sans_commission_le_texte_annonce_des_cotisations_gratuites(): void
    {
        // Le texte disait « les frais sont à la charge du cotisant » alors que
        // la commission était passée à 0 : exactement la divergence que ce
        // service existe pour empêcher.
        $corps = $this->bloc($this->config(['commission_paynala' => 0]), 'Frais');
        $this->assertStringContainsString('sans frais', $corps);

        $avec = $this->bloc($this->config(['commission_paynala' => 0.02]), 'Frais');
        $this->assertStringContainsString('à la charge du cotisant', $avec);
    }

    public function test_le_taux_marchand_est_annonce_et_suit_la_config(): void
    {
        $this->assertStringContainsString(
            '3 %',
            $this->bloc($this->config(['frais_marchand' => 0.03]), 'Frais'),
        );
        // À zéro, on ne dit pas « 0 % » : on dit qu'il n'y a pas de frais.
        $gratuit = $this->bloc($this->config(['frais_marchand' => 0]), 'Frais');
        $this->assertStringContainsString('sans frais', $gratuit);
        $this->assertStringNotContainsString('0 %', $gratuit);
    }

    public function test_le_bareme_de_retrait_vient_des_tranches(): void
    {
        // Une renégociation avec l'opérateur doit changer le texte sans qu'une
        // ligne de code ne bouge.
        $corps = $this->bloc($this->config(), 'Frais');
        $this->assertStringContainsString('3 % jusqu\'à 166 667 FCFA', $corps);
        $this->assertStringContainsString('5 000 FCFA au-delà', $corps);

        $autre = $this->bloc($this->config(['tranches' => [
            ['type' => 'pourcentage', 'valeur' => 0.02, 'montant_min' => 1, 'montant_max' => 200000],
        ]]), 'Frais');
        $this->assertStringContainsString('2 % jusqu\'à 200 000 FCFA', $autre);
    }

    public function test_un_reglage_invisible_ne_change_pas_la_version(): void
    {
        // La commission n'apparaît plus dans le texte : la faire varier ne doit
        // pas forcer les utilisateurs à réaccepter des conditions identiques.
        $avant = $this->service()->construire($this->config())['version'];
        $apres = $this->service()->construire($this->config(['commission_paynala' => 0.05]))['version'];

        $this->assertSame($avant, $apres);
    }

    public function test_matrice_a_zero_le_transfert_est_annonce_sans_frais(): void
    {
        // Même garantie qu'avant, formulation nouvelle : c'est la matrice
        // `frais_retrait` qui décide, jamais une phrase écrite en dur.
        $this->assertStringContainsString(
            'transfert du solde vers un numéro Mobile Money est sans frais',
            $this->bloc($this->config(), 'Frais'),
        );
    }

    public function test_un_seul_couple_non_nul_suffit_a_les_repercuter(): void
    {
        $cfg = $this->config(['frais_retrait' => [
            'cagnotte' => ['particulier' => 0, 'association' => 0],
            'tontine'  => ['particulier' => 0.03, 'association' => 0],
        ]]);

        $this->assertStringContainsString(
            'transfert du solde vers un numéro Mobile Money est facturé',
            $this->bloc($cfg, 'Frais'),
        );
    }

    public function test_les_plafonds_viennent_de_la_config(): void
    {
        $corps = $this->bloc($this->config(['plafond_par_envoi' => 750000]), 'Plafonds');

        $this->assertStringContainsString('750 000 FCFA', $corps);
        $this->assertStringContainsString('2 500 000 FCFA', $corps);
        $this->assertStringContainsString('10 000 000 FCFA', $corps);
    }

    public function test_la_version_change_avec_un_chiffre_affiche(): void
    {
        $avant = $this->service()->construire($this->config())['version'];
        $apres = $this->service()->construire($this->config(['plafond_par_envoi' => 750000]))['version'];

        $this->assertNotSame($avant, $apres);
    }
}
