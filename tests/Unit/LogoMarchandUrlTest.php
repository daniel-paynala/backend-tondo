<?php

namespace Tests\Unit;

use App\Services\LogoMarchand;
use Tests\TestCase;

/**
 * L'URL d'un logo : stable tant que l'image ne change pas, différente dès
 * qu'elle change.
 *
 * C'est toute la raison d'être de la colonne `logo_maj_at`. Les clients
 * mettent ces images en cache une journée ; sans jeton de version, remplacer
 * un logo ne changerait rien à l'écran de qui l'a déjà vu — et le commerçant
 * nous dirait, à raison, que son nouveau logo n'est pas passé.
 */
class LogoMarchandUrlTest extends TestCase
{
    private const ID = '11111111-1111-1111-1111-111111111111';

    public function test_pas_de_logo_pas_d_url(): void
    {
        // Null et non une URL vers une image par défaut : c'est au client de
        // décider de sa pastille d'initiales, pas au serveur.
        $this->assertNull(LogoMarchand::url(null, now(), self::ID));
    }

    public function test_l_url_porte_l_identifiant_du_marchand(): void
    {
        $url = LogoMarchand::url('marchands/x/logo.png', '2026-10-12T10:00:00+00:00', self::ID);

        $this->assertStringContainsString('/api/public/marchands/' . self::ID . '/logo', $url);
    }

    public function test_un_nouveau_televersement_change_l_url(): void
    {
        $avant = LogoMarchand::url('marchands/x/logo.png', '2026-10-12T10:00:00+00:00', self::ID);
        $apres = LogoMarchand::url('marchands/x/logo.png', '2026-10-12T11:00:00+00:00', self::ID);

        $this->assertNotSame($avant, $apres, 'Le cache client ne verrait jamais le nouveau logo.');
    }

    public function test_le_meme_logo_garde_la_meme_url(): void
    {
        // L'inverse compte autant : une URL qui changerait à chaque appel
        // ferait retélécharger toutes les vignettes à chaque ouverture.
        $horodatage = '2026-10-12T10:00:00+00:00';

        $this->assertSame(
            LogoMarchand::url('marchands/x/logo.png', $horodatage, self::ID),
            LogoMarchand::url('marchands/x/logo.png', $horodatage, self::ID),
        );
    }

    public function test_changer_de_format_ne_casse_pas_l_url(): void
    {
        // Le chemin n'apparaît pas dans l'URL : il reste une affaire interne,
        // et passer un PNG en WebP ne doit rien changer pour le client.
        $horodatage = '2026-10-12T10:00:00+00:00';

        $this->assertSame(
            LogoMarchand::url('marchands/x/logo.png', $horodatage, self::ID),
            LogoMarchand::url('marchands/x/logo.webp', $horodatage, self::ID),
        );
    }
}
