<?php

namespace Tests\Unit;

use App\Support\RetraitAgents;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Un agent de retrait remet des ESPÈCES. L'identifiant part dans un SMS que le
 * titulaire confronte à ce qu'il voit au comptoir ; le PIN est la seule chose
 * qui sépare un inconnu d'une session d'agent.
 */
class RetraitAgentsTest extends TestCase
{
    // ── Sigles ───────────────────────────────────────────────────────────────

    public function test_un_sigle_valide_fait_trois_lettres_majuscules(): void
    {
        $this->assertTrue(RetraitAgents::sigleValide('ECK'));
        $this->assertTrue(RetraitAgents::sigleValide('TPE'));
    }

    /**
     * Longueur fixe obligatoire : sans elle, « EC » + « KTPE » et « ECK » +
     * « TPE » produiraient le même identifiant.
     */
    public function test_un_sigle_de_longueur_differente_est_refuse(): void
    {
        foreach (['EC', 'ECKO', 'E', '', 'ec k', 'EC1', 'éck'] as $mauvais) {
            $this->assertFalse(RetraitAgents::sigleValide($mauvais), "Aurait dû être refusé : « {$mauvais} »");
        }
        $this->assertFalse(RetraitAgents::sigleValide(null));
    }

    public function test_la_saisie_d_un_sigle_est_normalisee(): void
    {
        $this->assertSame('ECK', RetraitAgents::normaliserSigle('  eck '));
    }

    // ── Identifiant ──────────────────────────────────────────────────────────

    public function test_l_identifiant_accole_les_sigles_et_un_numero_sur_trois_chiffres(): void
    {
        $this->assertSame('ECKTPE020', RetraitAgents::composerIdentifiant('ECK', 'TPE', 20));
        $this->assertSame('UBAGUI001', RetraitAgents::composerIdentifiant('UBA', 'GUI', 1));
    }

    /** Au-delà de 999 le numéro s'allonge, il ne boucle pas. */
    public function test_le_numero_s_allonge_au_dela_de_999(): void
    {
        $id = RetraitAgents::composerIdentifiant('ECK', 'TPE', 1000);

        $this->assertSame('ECKTPE1000', $id);
        $this->assertTrue(RetraitAgents::identifiantValide($id));
    }

    public function test_l_identifiant_compose_respecte_le_format_attendu_en_base(): void
    {
        foreach ([1, 9, 10, 99, 100, 999, 1000, 12345] as $n) {
            $this->assertTrue(
                RetraitAgents::identifiantValide(RetraitAgents::composerIdentifiant('ECK', 'TPE', $n)),
                "Numéro {$n}",
            );
        }
    }

    public function test_un_sigle_invalide_empeche_de_composer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetraitAgents::composerIdentifiant('EC', 'TPE', 1);
    }

    public function test_un_numero_nul_empeche_de_composer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetraitAgents::composerIdentifiant('ECK', 'TPE', 0);
    }

    public function test_la_saisie_d_un_identifiant_au_terminal_est_toleree(): void
    {
        $this->assertSame('ECKTPE020', RetraitAgents::normaliserIdentifiant(' ecktpe 020 '));
    }

    // ── PIN ──────────────────────────────────────────────────────────────────

    public function test_un_pin_ordinaire_est_accepte(): void
    {
        foreach (['4821', '0371', '9150', '2735'] as $pin) {
            $this->assertNull(RetraitAgents::motifRefusPin($pin), "Aurait dû être accepté : {$pin}");
        }
    }

    /** Ce sont les PIN qu'on essaie en premier sur un compte dont on ignore le code. */
    public function test_les_pin_devines_en_premier_sont_refuses(): void
    {
        $evidents = [
            '0000', '1111', '9999',          // chiffre répété
            '1234', '6789', '9876', '3210',  // suites
            '1212', '1010', '2020',          // motif répété
            '1122', '5566',                  // deux paires
            '1001', '2112',                  // symétriques — dont le « PIN par défaut » envisagé
        ];
        foreach ($evidents as $pin) {
            $this->assertNotNull(RetraitAgents::motifRefusPin($pin), "Aurait dû être refusé : {$pin}");
        }
    }

    public function test_un_pin_qui_n_a_pas_quatre_chiffres_est_refuse(): void
    {
        foreach (['123', '12345', 'abcd', '12a4', '', ' 482'] as $pin) {
            $this->assertNotNull(RetraitAgents::motifRefusPin($pin), "Aurait dû être refusé : « {$pin} »");
        }
        $this->assertNotNull(RetraitAgents::motifRefusPin(null));
    }

    public function test_le_pin_genere_n_est_jamais_trivial(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $pin = RetraitAgents::genererPin();
            $this->assertNull(RetraitAgents::motifRefusPin($pin), "PIN généré refusé : {$pin}");
        }
    }

    /** Un PIN commun à tous serait un PIN connu de tous. */
    public function test_les_pin_generes_varient(): void
    {
        $pins = [];
        for ($i = 0; $i < 50; $i++) {
            $pins[] = RetraitAgents::genererPin();
        }
        $this->assertGreaterThan(40, count(array_unique($pins)));
    }

    public function test_le_verrouillage_intervient_apres_cinq_echecs(): void
    {
        $this->assertSame(5, RetraitAgents::MAX_TENTATIVES_PIN);
    }

    // ── Clé d'API du partenaire ──────────────────────────────────────────────

    public function test_la_cle_porte_le_sigle_du_partenaire(): void
    {
        $this->assertStringStartsWith('tonji_pr_eck_', RetraitAgents::genererCleApi('ECK')['cle']);
    }

    public function test_l_empreinte_correspond_a_la_cle_sans_la_contenir(): void
    {
        $cle = RetraitAgents::genererCleApi('ECK');

        $this->assertSame($cle['hash'], RetraitAgents::empreinteCle($cle['cle']));
        $this->assertSame(64, strlen($cle['hash']));
        $this->assertStringNotContainsString($cle['cle'], $cle['hash']);
        $this->assertStringEndsWith($cle['apercu'], $cle['cle']);
    }
}
