<?php

namespace Tests\Unit;

use App\Support\Registre;
use PHPUnit\Framework\TestCase;

/**
 * La référence courte est une réécriture SANS PERTE du `trans_id`.
 *
 * C'est tout l'intérêt du format : rien n'est stocké, donc rien ne peut
 * diverger — mais encore faut-il que l'aller-retour soit exact pour chaque
 * préfixe déclaré, y compris ceux qui ne sont plus émis mais survivent en
 * production.
 */
class ReferenceCourteTest extends TestCase
{
    public function test_raccourcit_les_references_reelles(): void
    {
        // Exemples relevés en base de test.
        $this->assertSame('TM-BPU2JID53', Registre::court('TONJIMERCHANTBPU2JID53'));
        $this->assertSame('TI-CZRJ33KWGN', Registre::court('TONJIPAYINCZRJ33KWGN'));
        $this->assertSame('TO-FXUTLWHKB', Registre::court('TONJIPAYOUTFXUTLWHKB'));
        $this->assertSame('DI-WJUCRYXF7G', Registre::court('TONDOPAYINWJUCRYXF7G'));
    }

    public function test_distingue_entree_et_sortie(): void
    {
        // « payin » et « payout » s'abrègent tous deux en « TP » : c'est la
        // collision que la table de codes existe pour éviter.
        $entree = Registre::court('TONJIPAYINABCDEFGHIJ');
        $sortie = Registre::court('TONJIPAYOUTABCDEFGHI');

        $this->assertNotSame($entree, $sortie);
        $this->assertStringStartsWith('TI-', (string) $entree);
        $this->assertStringStartsWith('TO-', (string) $sortie);
    }

    public function test_aller_retour_exact_sur_tous_les_prefixes(): void
    {
        foreach (array_keys(Registre::CODES_COURTS) as $prefixe) {
            $transId = $prefixe . 'ABC123XYZ';
            $court   = Registre::court($transId);

            $this->assertNotNull($court, "pas de référence courte pour {$prefixe}");
            $this->assertSame($transId, Registre::long($court), "aller-retour rompu pour {$prefixe}");
        }
    }

    public function test_les_codes_courts_sont_tous_distincts(): void
    {
        $codes = array_values(Registre::CODES_COURTS);

        // Deux préfixes partageant un code rendraient le redéveloppement
        // ambigu : on retrouverait l'un pour l'autre.
        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    public function test_refuse_plutot_que_d_inventer(): void
    {
        // Préfixe inconnu : mieux vaut pas de référence courte qu'une
        // référence qu'on ne saurait pas redévelopper.
        $this->assertNull(Registre::court('AUTRECHOSE12345'));
        // Les anciens préfixes à tirets ne sont volontairement pas couverts.
        $this->assertNull(Registre::court('TONDO-WA-12345'));

        $this->assertNull(Registre::long('ZZ-ABC123'));
        $this->assertNull(Registre::long('sans-tiret-connu'));
        $this->assertNull(Registre::long('TM'));
        $this->assertNull(Registre::long('TM-'));
    }

    public function test_la_casse_ne_decide_pas_du_succes(): void
    {
        // Une référence recopiée d'un SMS arrive souvent tout en minuscules.
        // Les `trans_id` étant stockés en majuscules, une recherche sensible à
        // la casse ne trouverait rien.
        foreach (['tm-bpu2jid53', 'TM-bpu2jid53', 'tm-BPU2JID53', 'TM-BPU2JID53'] as $saisie) {
            $this->assertSame('TONJIMERCHANTBPU2JID53', Registre::long($saisie), $saisie);
        }
    }
}
