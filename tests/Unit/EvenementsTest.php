<?php

namespace Tests\Unit;

use App\Support\Evenements;
use PHPUnit\Framework\TestCase;

/**
 * Vocabulaire et filtrage de la télémétrie.
 *
 * Deux garanties y sont verrouillées : aucune série fantôme ne peut naître
 * d'une faute de frappe, et aucun contenu saisi ne peut entrer en base par une
 * clé de contexte inattendue.
 */
class EvenementsTest extends TestCase
{
    public function test_le_vocabulaire_est_ferme(): void
    {
        $this->assertTrue(Evenements::estConnu('cotisation_initiee'));
        $this->assertTrue(Evenements::estConnu('app_ouverte'));

        // Faute de frappe : rejetée, sinon elle creerait une série parallèle
        // que personne ne verrait jamais dans les entonnoirs.
        $this->assertFalse(Evenements::estConnu('cotisation_initie'));
        $this->assertFalse(Evenements::estConnu('n_importe_quoi'));
        $this->assertFalse(Evenements::estConnu(null));
        $this->assertFalse(Evenements::estConnu(''));
    }

    public function test_le_contexte_inattendu_est_ecarte(): void
    {
        // Le cœur de la garantie de confidentialité : même si un client envoyait
        // un numéro ou un commentaire, rien de tout ça n'atteindrait la base.
        $filtre = Evenements::filtrerContexte('montant_saisi', [
            'tranche'     => '1k-5k',
            'numero'      => '+24107607752',
            'montant'     => 3000,
            'commentaire' => 'je cotise pour ma mère',
        ]);

        $this->assertSame(['tranche' => '1k-5k'], $filtre);
    }

    public function test_un_evenement_sans_contexte_prevu_ne_garde_rien(): void
    {
        $this->assertSame([], Evenements::filtrerContexte('cotisation_ouverte', [
            'inattendu' => 'valeur',
        ]));
    }

    public function test_les_tranches_couvrent_les_bornes_reelles(): void
    {
        // Minimum produit : 100 FCFA. Plafond par transaction : 500 000.
        $this->assertSame('0-1k',     Evenements::tranche(100));
        $this->assertSame('0-1k',     Evenements::tranche(999));
        $this->assertSame('1k-5k',    Evenements::tranche(1000));
        $this->assertSame('1k-5k',    Evenements::tranche(3000));
        $this->assertSame('5k-25k',   Evenements::tranche(5000));
        $this->assertSame('25k-100k', Evenements::tranche(25000));
        $this->assertSame('100k+',    Evenements::tranche(100000));
        $this->assertSame('100k+',    Evenements::tranche(500000));
    }

    public function test_aucune_tranche_ne_revele_le_montant(): void
    {
        // Deux montants distincts d'une même tranche sont indiscernables : c'est
        // ce qui sort la donnée financière de la table de télémétrie.
        $this->assertSame(Evenements::tranche(1200), Evenements::tranche(4800));
    }
}
