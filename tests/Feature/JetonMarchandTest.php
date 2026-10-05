<?php

namespace Tests\Feature;

use App\Support\JetonMarchand;
use Tests\TestCase;

/**
 * Le jeton est la seule chose qui cloisonne les marchands entre eux : c'est
 * lui qui porte le numéro, et le numéro n'est jamais lu depuis la requête.
 */
class JetonMarchandTest extends TestCase
{
    public function test_un_jeton_valide_rend_son_numero(): void
    {
        $jeton = JetonMarchand::creer('+24177730634', 'projet-abc');
        $lu    = JetonMarchand::lire($jeton);

        $this->assertSame('+24177730634', $lu['numero']);
        $this->assertSame('projet-abc', $lu['projet']);
    }

    public function test_un_jeton_falsifie_est_refuse(): void
    {
        $jeton = JetonMarchand::creer('+24177730634', 'projet-abc');

        // Le chiffrement de Laravel est authentifié : toucher un seul
        // caractère invalide le MAC. Sans ça, on pourrait se fabriquer l'accès
        // au suivi d'un autre commerce.
        $altere = substr($jeton, 0, -4) . 'AAAA';

        $this->assertNull(JetonMarchand::lire($altere));
    }

    public function test_un_jeton_perime_est_refuse(): void
    {
        $jeton = JetonMarchand::creer('+24177730634', 'projet-abc');
        $this->assertNotNull(JetonMarchand::lire($jeton));

        // Un jeton n'est pas révocable : sa seule limite est la durée.
        $this->travel(JetonMarchand::DUREE + 60)->seconds();
        $this->assertNull(JetonMarchand::lire($jeton));
    }

    public function test_les_entrees_vides_ou_absurdes_sont_refusees(): void
    {
        foreach ([null, '', 'pas-un-jeton', base64_encode('nimporte quoi')] as $entree) {
            $this->assertNull(JetonMarchand::lire($entree), var_export($entree, true));
        }
    }
}
