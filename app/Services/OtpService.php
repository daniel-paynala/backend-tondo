<?php

namespace App\Services;

use RuntimeException;

/**
 * Façade OTP — point d'entrée unique pour l'envoi et la vérification des codes.
 *
 * Délègue au driver configuré dans OTP_DRIVER :
 *  - `dev`     : code statique 123456 (local uniquement, jamais en prod)
 *  - `twilio`  : Twilio Verify (code géré côté Twilio)
 *  - `paynala` : OTP interne (cache Laravel) + livraison SMS Wirepick
 *
 * Usage : app(OtpService::class)->sendOtp($phone) / ->checkOtp($phone, $code)
 *
 * Avantage : AuthController, BotService et tout futur appelant n'ont besoin
 * que de cette classe — le switch de driver se fait uniquement dans .env.
 */
class OtpService
{
    /** Code accepté en driver "dev" — jamais exposé en prod. */
    private const DEV_CODE = '123456';

    private string $driver;

    /** Numéro de test (review Apple) au format E.164, ou null si désactivé. */
    private ?string $testMsisdn;

    /** Code OTP fixe accepté pour le numéro de test. */
    private string $testOtp;

    public function __construct(
        private TwilioVerifyService $twilio,
        private PaynalaOtpService   $paynala,
    ) {
        $this->driver     = (string) config('services.otp.driver', 'dev');
        $this->testMsisdn = config('services.otp.test_msisdn');
        $this->testOtp    = (string) config('services.otp.test_otp', '000000');
    }

    /**
     * Vrai si [phoneE164] est le numéro de test whitelisté (review Apple / Google).
     * Comparaison tolérante au format (on compare les chiffres / les 9 derniers).
     *
     * PUBLIC à dessein : c'est la définition unique du « numéro de test ».
     * AuthController s'en sert pour court-circuiter la vérification KYC opérateur,
     * qui rejetterait ce numéro fictif (aucun compte Airtel Money réel derrière).
     * Ne jamais dupliquer cette logique ailleurs.
     */
    public function estNumeroTest(string $phoneE164): bool
    {
        if ($this->testMsisdn === null || $this->testMsisdn === '') {
            return false;
        }
        $a = preg_replace('/\D/', '', $phoneE164);
        $b = preg_replace('/\D/', '', $this->testMsisdn);
        return $a === $b || substr($a, -9) === substr($b, -9);
    }

    /**
     * Envoie un OTP au numéro E.164 fourni, selon le driver actif.
     *
     * En driver `dev`, aucun SMS n'est envoyé — le code 123456 est accepté.
     * Retourne le code uniquement en driver `dev` (pour les réponses API de test).
     *
     * @param  string      $phoneE164  Numéro au format E.164 (ex : "+24177123456").
     * @return string|null             Code OTP si driver=dev, null sinon.
     * @throws RuntimeException        Si l'envoi SMS échoue (propagé depuis le driver).
     */
    public function sendOtp(string $phoneE164): ?string
    {
        // Numéro de test (review Apple) : aucun SMS envoyé.
        if ($this->estNumeroTest($phoneE164)) {
            return null;
        }
        return match ($this->driver) {
            'twilio'  => $this->sendViaTwilio($phoneE164),
            'paynala' => $this->sendViaPaynala($phoneE164),
            default   => self::DEV_CODE,  // driver=dev : on n'envoie rien, code fixe.
        };
    }

    /**
     * Vérifie le code soumis par l'utilisateur, selon le driver actif.
     *
     * @param  string $phoneE164  Numéro au format E.164.
     * @param  string $code       Code à 6 chiffres soumis par l'utilisateur.
     * @return bool               true si le code est valide et non expiré.
     */
    public function checkOtp(string $phoneE164, string $code): bool
    {
        // Numéro de test (review Apple) : seul le code fixe est accepté.
        if ($this->estNumeroTest($phoneE164)) {
            return $code === $this->testOtp;
        }
        return match ($this->driver) {
            'twilio'  => $this->twilio->checkOtp($phoneE164, $code),
            'paynala' => $this->paynala->checkOtp($phoneE164, $code),
            default   => $code === self::DEV_CODE,  // driver=dev : compare au code statique.
        };
    }

    /**
     * Retourne le driver actif — utile pour les logs et les réponses API.
     */
    public function driver(): string
    {
        return $this->driver;
    }

    /** Envoie via Twilio Verify. Retourne null (Twilio gère le code lui-même). */
    private function sendViaTwilio(string $phoneE164): ?string
    {
        $this->twilio->sendOtp($phoneE164);
        return null;
    }

    /** Envoie via Wirepick (driver Paynala). Retourne null (code en cache, pas exposé). */
    private function sendViaPaynala(string $phoneE164): ?string
    {
        $this->paynala->sendOtp($phoneE164);
        return null;
    }
}
