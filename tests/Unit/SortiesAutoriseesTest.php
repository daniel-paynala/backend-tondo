<?php

namespace Tests\Unit;

use App\Models\TondoProjectConfig;
use PHPUnit\Framework\TestCase;

/**
 * La règle de cumul des verrous, isolée de la base.
 *
 * Elle tient en une phrase — « bloqué si l'un OU l'autre l'interdit » — et
 * c'est précisément le genre de phrase qu'on inverse par accident en la
 * recopiant dans un second endroit.
 */
class SortiesAutoriseesTest extends TestCase
{
    /** Reproduit la composition appliquée par le service. */
    private function autorise(bool $verrouCagnotte, bool $verrouGlobal): bool
    {
        return ! $verrouCagnotte && ! $verrouGlobal;
    }

    public function test_sans_verrou_la_sortie_est_permise(): void
    {
        $this->assertTrue($this->autorise(false, false));
    }

    public function test_le_verrou_de_la_collecte_suffit_a_bloquer(): void
    {
        $this->assertFalse($this->autorise(true, false));
    }

    public function test_le_verrou_global_suffit_a_bloquer(): void
    {
        // Le point important : une collecte non verrouillée ne doit PAS rouvrir
        // ce qu'une décision globale vient de fermer.
        $this->assertFalse($this->autorise(false, true));
    }

    public function test_les_deux_verrous_bloquent_aussi(): void
    {
        $this->assertFalse($this->autorise(true, true));
    }

    public function test_l_etat_par_defaut_n_interdit_rien(): void
    {
        foreach (TondoProjectConfig::VERROUS_OUVERTS as $type => $actions) {
            foreach ($actions as $action => $bloque) {
                $this->assertFalse($bloque, "{$type}.{$action} devrait être ouvert par défaut");
            }
        }
    }

    public function test_les_deux_types_de_compte_sont_couverts(): void
    {
        // Un type absent de la matrice retomberait sur « non bloqué » : il faut
        // donc que les deux soient déclarés, sinon le verrou global serait
        // silencieusement sans effet pour l'un d'eux.
        $this->assertSame(
            ['particulier', 'association'],
            array_keys(TondoProjectConfig::VERROUS_OUVERTS),
        );
        foreach (TondoProjectConfig::VERROUS_OUVERTS as $actions) {
            $this->assertSame(['transfert', 'marchand'], array_keys($actions));
        }
    }
}
