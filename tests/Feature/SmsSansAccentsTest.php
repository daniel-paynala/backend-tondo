<?php

namespace Tests\Feature;

use App\Services\WirepickSmsService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Aucun caractère accentué ne doit quitter Tonji par SMS.
 *
 * Un accent fait basculer le message de l'alphabet GSM-7 vers l'UCS-2, et
 * quelque chose en aval de Wirepick le perd : accepté, facturé, annoncé
 * « DLG » — jamais reçu.
 *
 * Ce n'est pas une précaution théorique. Du 7 au 14 octobre 2026, AUCUN OTP
 * n'a été délivré parce que le texte disait « Votre code de vérification ».
 * La même phrase écrite « verification » est arrivée dans la seconde, vérifiée
 * deux fois sur le même téléphone.
 *
 * Ce test existe pour qu'on ne « rétablisse pas les accents pour faire
 * propre » dans six mois.
 */
class SmsSansAccentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.wirepick.client_id' => 'Test',
            'services.wirepick.password'  => 'secret',
            'services.wirepick.from'      => 'Tonji',
        ]);

        Http::fake(['*' => Http::response(
            '<messages><sms><msgid>1</msgid><total_cost>15.0</total_cost></sms></messages>',
            200,
        )]);
    }

    /** Le texte réellement transmis à Wirepick. */
    private function texteEnvoye(string $message): string
    {
        app(WirepickSmsService::class)->send('+24177000000', $message);

        $envoye = null;
        Http::assertSent(function ($requete) use (&$envoye) {
            $envoye = $requete->data()['text'] ?? '';

            return true;
        });

        return (string) $envoye;
    }

    public function test_le_message_d_otp_part_sans_accent(): void
    {
        $texte = $this->texteEnvoye(
            'Tonji - Votre code de vérification : 123456. Valable 5 minutes.'
        );

        $this->assertStringContainsString('verification', $texte);
        $this->assertStringNotContainsString('é', $texte);
    }

    public function test_aucun_caractere_hors_ascii_ne_sort(): void
    {
        // Le contrôle général : accents, tirets longs, apostrophes typographiques.
        $texte = $this->texteEnvoye(
            'Retrait validé — présentez-vous chez l’agent. Référence : ÀÉÎÔÙ çœ.'
        );

        $this->assertSame(
            $texte,
            (string) preg_replace('/[^\x20-\x7E]/', '', $texte),
            "Un caractère hors ASCII est parti : « {$texte} »",
        );
    }

    public function test_un_message_deja_propre_n_est_pas_abime(): void
    {
        $propre = 'Tonji - Code 123456. Valable 5 minutes.';

        $this->assertSame($propre, $this->texteEnvoye($propre));
    }

    public function test_les_chiffres_du_code_survivent(): void
    {
        // La translittération ne doit toucher ni au code ni à sa lisibilité.
        $this->assertStringContainsString(
            '483921',
            $this->texteEnvoye('Votre code de vérification : 483921.'),
        );
    }
}
