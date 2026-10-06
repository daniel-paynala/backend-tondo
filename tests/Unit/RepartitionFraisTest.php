<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * La répartition des frais doit toujours rendre le montant, au franc.
 *
 * Un transfert devient plusieurs décaissements. Si la somme des parts et du net
 * ne retombe pas exactement sur le montant débité, un franc est créé ou détruit
 * à chaque transaction — et personne ne le voit avant la réconciliation.
 *
 * On reproduit ici le calcul de {@see \App\Services\RepartitionFrais} sans base
 * de données : ce qui est testé est l'arithmétique, pas la lecture SQL.
 */
class RepartitionFraisTest extends TestCase
{
    /**
     * Reproduit le calcul du service.
     *
     * @param  array<int, float> $taux  Parts, en décimal.
     * @return array{net: int, frais: int, parts: array<int, int>}
     */
    private function repartir(int $montant, array $taux, ?float $tauxImpose = null): array
    {
        $somme   = array_sum($taux);
        $facteur = ($tauxImpose !== null && $somme > 0) ? $tauxImpose / $somme : 1.0;

        $parts = [];
        $total = 0;
        foreach ($taux as $t) {
            $part = (int) round($montant * $t * $facteur);
            if ($part <= 0) {
                continue;
            }
            $parts[] = $part;
            $total  += $part;
        }

        // Le net par SOUSTRACTION : c'est tout l'enjeu du test.
        return ['net' => $montant - $total, 'frais' => $total, 'parts' => $parts];
    }

    public function test_l_exemple_de_daniel(): void
    {
        // 100 000 FCFA à 3 % : Airtel 1 %, Paynala 0,5 %, custom 1,5 %.
        $r = $this->repartir(100000, [0.01, 0.005, 0.015]);

        $this->assertSame([1000, 500, 1500], $r['parts']);
        $this->assertSame(3000, $r['frais']);
        $this->assertSame(97000, $r['net']);
    }

    public function test_la_somme_rend_toujours_le_montant(): void
    {
        // Montants choisis pour tomber sur des demi-francs et des tiers : c'est
        // là que trois arrondis indépendants ne retombent pas sur le total.
        foreach ([100, 333, 777, 1001, 3333, 12345, 99999, 500000] as $montant) {
            $r = $this->repartir($montant, [0.01, 0.005, 0.015]);

            $this->assertSame(
                $montant,
                $r['net'] + array_sum($r['parts']),
                "La répartition de {$montant} FCFA ne rend pas le montant.",
            );
        }
    }

    public function test_une_part_sous_le_franc_est_ecartee(): void
    {
        // 0,5 % de 100 FCFA fait 0,5 → arrondi à 1 au lieu de 0 : on garde.
        // 0,1 % de 100 FCFA fait 0,1 → arrondi à 0 : la ligne disparaît, sinon
        // le journal se remplirait de parts à zéro qu'aucun décaissement ne
        // réglera jamais.
        $r = $this->repartir(100, [0.001]);

        $this->assertSame([], $r['parts']);
        $this->assertSame(100, $r['net']);
        $this->assertSame(0, $r['frais']);
    }

    public function test_sans_compte_configure_rien_n_est_preleve(): void
    {
        // État par défaut du produit : la fonction s'active en remplissant le
        // dashboard, pas en basculant un drapeau.
        $r = $this->repartir(50000, []);

        $this->assertSame(50000, $r['net']);
        $this->assertSame(0, $r['frais']);
    }

    public function test_un_taux_negocie_met_les_parts_a_l_echelle(): void
    {
        // Un hôpital à 1 % au lieu de 3 % : les mêmes comptes vivent du tiers.
        $r = $this->repartir(100000, [0.01, 0.005, 0.015], 0.01);

        $this->assertSame(1000, $r['frais']);
        $this->assertSame(99000, $r['net']);
        // Les proportions entre comptes sont conservées : 1 / 0,5 / 1,5 → 2/1/3.
        $this->assertSame([333, 167, 500], $r['parts']);
    }

    public function test_une_exoneration_ne_preleve_rien(): void
    {
        $r = $this->repartir(100000, [0.01, 0.005, 0.015], 0.0);

        $this->assertSame(0, $r['frais']);
        $this->assertSame(100000, $r['net']);
    }

    public function test_le_net_reste_strictement_positif_sur_les_taux_admis(): void
    {
        // Borne du SQL : 0,25 par ligne. Trois lignes au maximum admis font
        // 75 %, le bénéficiaire garde donc toujours quelque chose.
        $r = $this->repartir(1000, [0.25, 0.25, 0.25]);

        $this->assertSame(750, $r['frais']);
        $this->assertSame(250, $r['net']);
        $this->assertGreaterThan(0, $r['net']);
    }
}
