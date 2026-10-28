<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Prépare l'application avant chaque test.
     *
     * La suite ne doit dépendre d'aucun identifiant présent dans le `.env` de
     * la machine. Deux services refusent de se construire quand leur config
     * manque — `TwilioVerifyService` et `WirepickSmsService` — et ils sont
     * injectés dans `OtpService`, lui-même résolu par les contrôleurs
     * d'inscription. Sur un clone neuf, celui de la CI, ces tests répondaient
     * donc 500. Cinq passaient en local et échouaient ailleurs pour cette
     * seule raison.
     *
     * On installe donc des valeurs factices. Elles suffisent à construire les
     * clients ; aucun test n'envoie réellement de SMS, et un appel sortant
     * resterait de toute façon à proscrire dans un test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.twilio.account_sid' => 'ACtest0000000000000000000000000000',
            'services.twilio.auth_token' => 'token-de-test',
            'services.twilio.verify_service_sid' => 'VAtest0000000000000000000000000000',
            'services.wirepick.client_id' => 'client-de-test',
            'services.wirepick.password' => 'mot-de-passe-de-test',
        ]);
    }
}
