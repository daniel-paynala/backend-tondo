<?php

namespace Tests\Unit;

use App\Support\CollecteGuard;
use PHPUnit\Framework\TestCase;

/**
 * Le verrou administratif des cotisations.
 *
 * Pendant à l'entrée de ce que `SortiesAutorisees` fait à la sortie, et soumis
 * à la même règle du OU. Ces cas-là décrivent une décision humaine — couper la
 * collecte d'une cagnotte précise, ou de tout un type de compte — et c'est
 * exactement le genre de contrôle qui se perd en silence lors d'un
 * refactoring : rien ne casse, il laisse simplement passer.
 */
class VerrouCollecteTest extends TestCase
{
    /** Collecte ordinaire, aucun verrou : rien ne doit gêner. */
    private function ouverte(bool $cagnotte = false, bool $global = false): ?string
    {
        return CollecteGuard::bloquee(
            'prive',              // visibilité
            null,                 // statut de modération
            'particulier',        // type de compte du gérant
            null,                 // statut de l'organisation
            'cagnotte_ouverte',
            null,                 // pas de date de fin
            $cagnotte,
            $global,
        );
    }

    public function test_sans_verrou_la_collecte_passe(): void
    {
        $this->assertNull($this->ouverte());
    }

    public function test_le_verrou_de_la_cagnotte_suffit(): void
    {
        $this->assertSame(
            'Les cotisations ont été suspendues pour cette cagnotte. Veuillez contacter le support.',
            $this->ouverte(cagnotte: true),
        );
    }

    public function test_le_verrou_global_suffit_aussi(): void
    {
        // Le cas qui compte vraiment : la cagnotte n'a rien de bloqué, et la
        // collecte est pourtant fermée. Un ET l'aurait laissée ouverte.
        $this->assertNotNull($this->ouverte(global: true));
    }

    public function test_les_deux_verrous_ensemble_ne_changent_rien(): void
    {
        $this->assertNotNull($this->ouverte(cagnotte: true, global: true));
    }

    public function test_le_verrou_prime_sur_les_raisons_automatiques(): void
    {
        // Une cagnotte publique non validée ET verrouillée : c'est le verrou
        // qu'on annonce. Dire « en cours de validation » enverrait le cotisant
        // attendre une modération qui ne débloquera rien.
        $message = CollecteGuard::bloquee(
            'public',
            'en_attente',
            'particulier',
            null,
            'cagnotte_ouverte',
            null,
            true,
            false,
        );

        $this->assertStringContainsString('suspendues', $message);
        $this->assertStringNotContainsString('validation', $message);
    }

    public function test_le_message_dit_quoi_faire(): void
    {
        // Un refus sans suite possible laisse le cotisant sans recours : il
        // doit savoir vers qui se tourner.
        $this->assertStringContainsString('support', $this->ouverte(cagnotte: true));
    }
}
