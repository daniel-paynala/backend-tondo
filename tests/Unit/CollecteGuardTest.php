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
}
