<?php

namespace Tests\Unit;

use App\Support\TypesAgent;
use PHPUnit\Framework\TestCase;

/**
 * Un agent remet des ESPÈCES. Ce qui se joue ici n'est pas de la validation de
 * formulaire : le code part dans un SMS que le bénéficiaire confronte à ce
 * qu'il voit au comptoir, et la clé d'API autorise à déclencher une sortie
 * d'argent liquide.
 */
class TypesAgentTest extends TestCase
{
    // ── Vocabulaire ──────────────────────────────────────────────────────────

    public function test_les_types_connus_sont_acceptes(): void
    {
        $this->assertTrue(TypesAgent::typeValide('tpe'));
        $this->assertTrue(TypesAgent::typeValide('guichet'));
    }

    public function test_un_type_inconnu_est_refuse(): void
    {
        $this->assertFalse(TypesAgent::typeValide('borne'));
        $this->assertFalse(TypesAgent::typeValide(null));
        $this->assertFalse(TypesAgent::typeValide('TPE')); // la casse compte
    }

    public function test_les_statuts_connus_sont_acceptes(): void
    {
        $this->assertTrue(TypesAgent::statutValide('actif'));
        $this->assertTrue(TypesAgent::statutValide('suspendu'));
        $this->assertFalse(TypesAgent::statutValide('inactif'));
    }

    // ── Code public ──────────────────────────────────────────────────────────

    public function test_le_code_genere_respecte_le_format(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $code = TypesAgent::genererCode();
            $this->assertTrue(
                TypesAgent::codeValide($code),
                "Code hors format : {$code}",
            );
        }
    }

    /**
     * Le code est lu à voix haute au comptoir et confronté au SMS. Les
     * caractères qui se confondent à l'oral ou à l'œil n'y ont pas leur place.
     */
    public function test_le_code_evite_les_lettres_ambigues(): void
    {
        $vues = [];
        for ($i = 0; $i < 500; $i++) {
            $vues[TypesAgent::genererCode()[0]] = true;
        }

        foreach (['I', 'O', 'Q'] as $interdite) {
            $this->assertArrayNotHasKey(
                $interdite,
                $vues,
                "La lettre {$interdite} se confond avec un chiffre et ne doit pas être tirée.",
            );
        }
    }

    public function test_un_code_mal_forme_est_refuse(): void
    {
        foreach (['A1042', 'A-104', 'A-10425', 'a-1042', 'AA-1042', '', 'A-104X'] as $mauvais) {
            $this->assertFalse(
                TypesAgent::codeValide($mauvais),
                "Aurait dû être refusé : « {$mauvais} »",
            );
        }
        $this->assertFalse(TypesAgent::codeValide(null));
    }

    /**
     * Le tirage est aléatoire et non séquentiel : une suite croissante
     * publierait le nombre d'agents et l'ordre des recrutements.
     */
    public function test_les_codes_ne_se_suivent_pas(): void
    {
        $codes = [];
        for ($i = 0; $i < 50; $i++) {
            $codes[] = TypesAgent::genererCode();
        }

        $this->assertGreaterThan(
            40,
            count(array_unique($codes)),
            'Le tirage produit trop de doublons pour être aléatoire.',
        );
    }

    // ── Clé d'API ────────────────────────────────────────────────────────────

    public function test_la_cle_porte_le_code_de_l_agent(): void
    {
        $cle = TypesAgent::genererCleApi('A-1042');

        // Le code préfixe la clé pour qu'une fuite dans un journal désigne
        // immédiatement l'agent à révoquer.
        $this->assertStringStartsWith('tonji_ag_a1042_', $cle['cle']);
    }

    public function test_l_empreinte_correspond_a_la_cle(): void
    {
        $cle = TypesAgent::genererCleApi('A-1042');

        $this->assertSame($cle['hash'], TypesAgent::empreinte($cle['cle']));
        $this->assertSame(64, strlen($cle['hash']), 'SHA-256 fait 64 caractères hexadécimaux.');
    }

    public function test_la_cle_en_clair_n_apparait_pas_dans_l_empreinte(): void
    {
        $cle = TypesAgent::genererCleApi('A-1042');

        $this->assertStringNotContainsString($cle['cle'], $cle['hash']);
    }

    /** L'aperçu sert à reconnaître une clé, pas à la reconstituer. */
    public function test_l_apercu_reste_court_et_terminal(): void
    {
        $cle = TypesAgent::genererCleApi('A-1042');

        $this->assertSame(4, strlen($cle['apercu']));
        $this->assertStringEndsWith($cle['apercu'], $cle['cle']);
    }

    public function test_deux_cles_ne_se_ressemblent_pas(): void
    {
        $a = TypesAgent::genererCleApi('A-1042');
        $b = TypesAgent::genererCleApi('A-1042');

        $this->assertNotSame($a['cle'], $b['cle']);
        $this->assertNotSame($a['hash'], $b['hash']);
    }
}
