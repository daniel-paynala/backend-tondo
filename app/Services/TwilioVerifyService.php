<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Client minimal de Twilio Verify (REST API).
 *
 * Pourquoi pas le SDK officiel `twilio/sdk` : on n'utilise que 2
 * endpoints (Verifications + VerificationCheck). Le SDK pèse ~10 MB
 * et bloque l'autoload sous Octane. Une wrapper HTTP fait largement
 * l'affaire et garde le control sur les timeouts et les retries.
 *
 * Doc : https://www.twilio.com/docs/verify/api/verification
 */
class TwilioVerifyService
{
    private string $accountSid;
    private string $authToken;
    private string $serviceSid;

    /**
     * Initialise le client Twilio depuis les variables d'environnement Laravel.
     *
     * Lève une exception dès la construction si l'une des trois variables
     * est absente, afin de détecter une mauvaise config au boot (pas à l'envoi).
     *
     * Variables requises :
     *   TWILIO_ACCOUNT_SID         — SID du compte Twilio (commence par "AC").
     *   TWILIO_AUTH_TOKEN          — Token d'authentification HTTP Basic.
     *   TWILIO_VERIFY_SERVICE_SID  — SID du service Verify (commence par "VA").
     *
     * @throws RuntimeException  Si l'une des variables est vide.
     */
    public function __construct()
    {
        $this->accountSid = (string) config('services.twilio.account_sid');
        $this->authToken  = (string) config('services.twilio.auth_token');
        $this->serviceSid = (string) config('services.twilio.verify_service_sid');

        // Validation stricte au démarrage pour éviter des erreurs silencieuses à l'envoi.
        if ($this->accountSid === '' || $this->authToken === '' || $this->serviceSid === '') {
            throw new RuntimeException(
                'Twilio mal configuré : TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN et TWILIO_VERIFY_SERVICE_SID requis.'
            );
        }
    }

    /**
     * Déclenche l'envoi d'un OTP par SMS au numéro fourni (format E.164).
     * Twilio génère le code, l'envoie, et l'associe au numéro pour 10 min.
     *
     * @return array{sid: string, status: string} Le SID + status ('pending')
     * @throws RuntimeException si Twilio rejette (numéro invalide, quota, etc.)
     */
    public function sendOtp(string $phoneE164): array
    {
        // URL de l'endpoint Twilio Verify — le serviceSid identifie le service de vérification.
        $url = "https://verify.twilio.com/v2/Services/{$this->serviceSid}/Verifications";

        $response = $this->http()->asForm()->post($url, [
            'To'      => $phoneE164, // Numéro E.164 du destinataire (ex : "+24177123456").
            'Channel' => 'sms',      // Seul SMS est utilisé pour Tondo (pas voice/whatsapp).
        ]);

        if (! $response->successful()) {
            $body = $response->json();
            // Le code Twilio (ex : 60200 = numéro invalide) est inclus dans le message
            // pour faciliter le debugging en production.
            $msg  = $body['message'] ?? 'Erreur Twilio inconnue';
            $code = $body['code'] ?? $response->status();
            Log::warning("[twilio] send-otp failed for {$phoneE164}", $body ?? []);
            throw new RuntimeException("Twilio Verify a refusé l'envoi ({$code}): {$msg}");
        }

        $data = $response->json();
        // On ne retourne que sid + status — le code OTP n'est JAMAIS retourné par l'API.
        return [
            'sid'    => (string) $data['sid'],
            'status' => (string) $data['status'], // 'pending' si envoyé avec succès.
        ];
    }

    /**
     * Vérifie qu'un code soumis correspond bien au dernier OTP envoyé
     * pour ce numéro. Twilio gère le rate-limit (5 essais max) et
     * l'expiration (10 min) côté serveur.
     *
     * @return bool true si `status=approved`, false sinon (incorrect, expiré, …)
     */
    public function checkOtp(string $phoneE164, string $code): bool
    {
        $url = "https://verify.twilio.com/v2/Services/{$this->serviceSid}/VerificationCheck";

        $response = $this->http()->asForm()->post($url, [
            'To' => $phoneE164,
            'Code' => $code,
        ]);

        if (! $response->successful()) {
            // 404 = pas de pending verification (déjà validée ou expirée).
            // On loggue mais on ne throw pas — l'appelant verra `false` et
            // peut renvoyer un "code invalide" générique à l'user.
            Log::info("[twilio] check-otp non successful for {$phoneE164}", $response->json() ?? []);
            return false;
        }

        $status = $response->json('status');
        return $status === 'approved';
    }

    /**
     * Construit le client HTTP préconfiguré pour l'API Twilio.
     *
     * - Basic Auth : accountSid comme username, authToken comme password.
     * - Timeout 8 s : raisonnable pour un SMS (Twilio répond généralement < 2 s).
     * - retry(1, 200) : une seule nouvelle tentative après 200 ms en cas d'erreur réseau
     *   transitoire. `throw: false` signifie qu'on ne lève pas d'exception sur l'erreur
     *   de retry — l'appelant gère lui-même le statut de la réponse.
     */
    private function http(): PendingRequest
    {
        return Http::withBasicAuth($this->accountSid, $this->authToken)
            ->timeout(8)
            ->retry(1, 200, throw: false);
    }
}
