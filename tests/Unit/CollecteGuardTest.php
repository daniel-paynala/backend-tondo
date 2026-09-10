<?php

namespace Tests\Unit;

use App\Support\CollecteGuard;
use PHPUnit\Framework\TestCase;

/**
 * Garde de collecte : autorise/bloque une cotisation selon la visibilité de la
 * cagnotte, son statut de modération, et le statut du dossier de l'association.
 */
class CollecteGuardTest extends TestCase
{
    public function test_cagnotte_privee_particulier_autorisee(): void
    {
        $this->assertNull(CollecteGuard::bloquee('prive', 'non_requis', 'particulier', null));
    }

    public function test_cagnotte_privee_association_approuvee_autorisee(): void
    {
        $this->assertNull(CollecteGuard::bloquee('prive', 'non_requis', 'association', 'approuve'));
    }

    public function test_cagnotte_publique_en_attente_bloquee(): void
    {
        $msg = CollecteGuard::bloquee('public', 'en_attente', 'association', 'approuve');
        $this->assertNotNull($msg);
        $this->assertStringContainsString('validation', $msg);
    }

    public function test_cagnotte_publique_rejetee_bloquee(): void
    {
        $this->assertNotNull(CollecteGuard::bloquee('public', 'rejetee', 'association', 'approuve'));
    }

    public function test_cagnotte_publique_approuvee_autorisee(): void
    {
        $this->assertNull(CollecteGuard::bloquee('public', 'approuvee', 'association', 'approuve'));
    }

    public function test_association_suspendue_bloquee_meme_en_prive(): void
    {
        // La suspension coupe la collecte, y compris sur une cagnotte privée.
        $msg = CollecteGuard::bloquee('prive', 'non_requis', 'association', 'suspendu');
        $this->assertNotNull($msg);
        $this->assertStringContainsString('association', $msg);
    }

    public function test_association_en_attente_bloquee(): void
    {
        $this->assertNotNull(CollecteGuard::bloquee('prive', 'non_requis', 'association', 'en_attente'));
    }

    public function test_particulier_jamais_bloque_par_le_statut_orga(): void
    {
        // Un particulier n'a pas d'orga : aucun blocage lié à l'asso.
        $this->assertNull(CollecteGuard::bloquee('prive', 'non_requis', 'particulier', null));
    }

    // ── Date de fin dépassée ────────────────────────────────────────────────

    public function test_une_cotisation_ouverte_echue_est_bloquee(): void
    {
        $hier = date('Y-m-d', strtotime('-1 day'));

        $this->assertNotNull(CollecteGuard::bloquee(
            'prive', 'non_requis', 'particulier', null, 'cagnotte_ouverte', $hier,
        ));
    }

    public function test_l_echeance_reste_ouverte_tout_le_jour_dit(): void
    {
        // `date_fin` est une DATE : une échéance « au 10 » doit accepter les
        // cotisations pendant tout le 10, pas s'arrêter à minuit du 9 au 10.
        $aujourdhui = date('Y-m-d');

        $this->assertNull(CollecteGuard::bloquee(
            'prive', 'non_requis', 'particulier', null, 'cagnotte_ouverte', $aujourdhui,
        ));
    }

    public function test_une_cotisation_sans_date_de_fin_n_est_jamais_echue(): void
    {
        $this->assertNull(CollecteGuard::bloquee(
            'prive', 'non_requis', 'particulier', null, 'cagnotte_ouverte', null,
        ));
        $this->assertNull(CollecteGuard::bloquee(
            'prive', 'non_requis', 'particulier', null, 'cagnotte_ouverte', '',
        ));
    }

    public function test_une_tontine_n_est_pas_concernee_par_la_date_de_fin(): void
    {
        // Une tontine n'a pas d'échéance de collecte : ses cycles la rythment.
        $hier = date('Y-m-d', strtotime('-1 day'));

        $this->assertNull(CollecteGuard::bloquee(
            'prive', 'non_requis', 'particulier', null, 'tontine_periodique', $hier,
        ));
    }

    public function test_les_appels_sans_les_nouveaux_champs_restent_valides(): void
    {
        // Compatibilité : un appelant qui n'a pas encore été mis à jour ne doit
        // pas voir son comportement changer.
        $this->assertNull(CollecteGuard::bloquee('prive', 'non_requis', 'particulier', null));
    }
}
