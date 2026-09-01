<?php

namespace Tests\Unit;

use App\Support\FcmSupport;
use PHPUnit\Framework\TestCase;

/**
 * Helpers FCM purs : stringification des `data`, détection d'un token mort,
 * encodage base64url.
 */
class FcmSupportTest extends TestCase
{
    public function test_stringifier_convertit_scalaires_et_json(): void
    {
        $out = FcmSupport::stringifier([
            'type'      => 'moderation',
            'nombre'    => 5,
            'flag'      => true,
            'reference' => 123456,
            'data'      => ['x' => 1],
        ]);

        $this->assertSame('moderation', $out['type']);
        $this->assertSame('5', $out['nombre']);      // int → string
        $this->assertSame('1', $out['flag']);        // bool true → '1'
        $this->assertSame('123456', $out['reference']);
        $this->assertSame('{"x":1}', $out['data']);  // tableau → JSON
    }

    public function test_stringifier_force_les_cles_en_chaines(): void
    {
        $out = FcmSupport::stringifier([0 => 'a', 1 => 'b']);
        $this->assertArrayHasKey('0', $out);
        $this->assertSame('a', $out['0']);
    }

    public function test_token_invalide_sur_404(): void
    {
        $this->assertTrue(FcmSupport::tokenInvalide(404, null));
    }

    public function test_token_invalide_sur_status_unregistered(): void
    {
        $this->assertTrue(FcmSupport::tokenInvalide(200, ['error' => ['status' => 'UNREGISTERED']]));
        $this->assertTrue(FcmSupport::tokenInvalide(200, ['error' => ['status' => 'NOT_FOUND']]));
    }

    public function test_token_valide_sur_400_et_2xx(): void
    {
        // 400 (payload) ne purge pas le token ; 200 sans erreur non plus.
        $this->assertFalse(FcmSupport::tokenInvalide(400, ['error' => ['status' => 'INVALID_ARGUMENT']]));
        $this->assertFalse(FcmSupport::tokenInvalide(200, null));
        $this->assertFalse(FcmSupport::tokenInvalide(500, ['error' => ['status' => 'INTERNAL']]));
    }

    public function test_base64url_sans_padding_ni_caracteres_non_surs(): void
    {
        // La sortie ne doit contenir ni '=', ni '+', ni '/'.
        $encoded = FcmSupport::base64url(random_bytes(30));
        $this->assertStringNotContainsString('=', $encoded);
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
    }

    public function test_base64url_valeur_connue(): void
    {
        // base64("foo") = "Zm9v" (déjà sans padding ni caractère spécial).
        $this->assertSame('Zm9v', FcmSupport::base64url('foo'));
        // base64("f") = "Zg==" → base64url retire le padding.
        $this->assertSame('Zg', FcmSupport::base64url('f'));
    }
}
