<?php

namespace Tests\Unit;

use App\Models\TondoMarchand;
use PHPUnit\Framework\TestCase;

/**
 * Le taux d'une fiche l'emporte sur celui du projet.
 *
 * Le piège est **zéro** : il doit se comporter comme une valeur, pas comme une
 * absence. Un partenaire exonéré reste exonéré quand le taux du projet change,
 * alors qu'une fiche sans taux le suit. Un `?:` au lieu d'un `??` suffirait à
 * inverser ce comportement sans que rien ne le signale.
 */
class TauxMarchandTest extends TestCase
{
    private function fiche(?float $taux): TondoMarchand
    {
        $m = new TondoMarchand();
        $m->frais_taux = $taux;

        return $m;
    }

    public function test_sans_taux_la_fiche_suit_le_projet(): void
    {
        $this->assertSame(0.03, $this->fiche(null)->tauxApplicable(0.03));
        $this->assertSame(0.05, $this->fiche(null)->tauxApplicable(0.05));
    }

    public function test_le_taux_de_la_fiche_l_emporte(): void
    {
        $this->assertSame(0.01, $this->fiche(0.01)->tauxApplicable(0.03));
        // Au-dessus du taux projet aussi : une négociation peut aller
        // dans les deux sens.
        $this->assertSame(0.08, $this->fiche(0.08)->tauxApplicable(0.03));
    }

    public function test_zero_exonere_et_ne_suit_plus_le_projet(): void
    {
        $exonere = $this->fiche(0.0);

        $this->assertSame(0.0, $exonere->tauxApplicable(0.03));
        // Le projet monte : l'exonéré reste à zéro.
        $this->assertSame(0.0, $exonere->tauxApplicable(0.05));
    }
}
