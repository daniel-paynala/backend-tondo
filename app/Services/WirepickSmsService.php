<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Client HTTP pour l'API SMS Wirepick (partenaire MTN Business).
 *
 * Ce service est responsable UNIQUEMENT de la livraison du message —
 * il n'a aucune connaissance de la logique OTP (codes, expiration, tentatives).
 * C'est [PaynalaOtpService] qui orchestre l'OTP et appelle ce service.
 *
 * Doc : /docs/Wirepick HTTP API-updated with sample code-1.pdf
 * Endpoint : GET|POST https://api.wirepick.com/httpsms/send
 */
class WirepickSmsService
{
    /** URL de l'endpoint d'envoi Wirepick. */
    private const API_URL = 'https://api.wirepick.com/httpsms/send';

    /** Statut XML retourné par Wirepick quand le SMS est accepté. */
    private const STATUS_ACCEPTED = 'Accepted';

    private string $clientId;
    private string $password;
    /** Nom d'expéditeur affiché sur le téléphone du destinataire (max 11 caractères). */
    private string $from;

    /**
     * Initialise le client Wirepick depuis la configuration Laravel.
     *
     * Variables requises :
     *   WIREPICK_CLIENT_ID — identifiant du compte Wirepick.
     *   WIREPICK_PASSWORD  — mot de passe du compte Wirepick.
     *   WIREPICK_FROM      — nom d'expéditeur (défaut : "Tonji").
     *
     * @throws RuntimeException  Si les credentials sont absents au boot.
     */
    public function __construct()
    {
        $this->clientId = (string) config('services.wirepick.client_id');
        $this->password = (string) config('services.wirepick.password');
        $this->from     = (string) config('services.wirepick.from', 'Tonji');

        if ($this->clientId === '' || $this->password === '') {
            throw new RuntimeException(
                'Wirepick mal configuré : WIREPICK_CLIENT_ID et WIREPICK_PASSWORD sont requis.'
            );
        }
    }

    /**
     * Envoie un SMS au numéro E.164 fourni.
     *
     * Le `+` est retiré du numéro car Wirepick attend le format international
     * sans préfixe (ex : +24177123456 → 24177123456).
     *
     * La réponse est XML ; on parse le statut pour détecter les rejets silencieux
     * (HTTP 200 mais status ≠ "Accepted").
     *
     * @param string $phoneE164  Numéro du destinataire au format E.164 (ex : "+24177123456").
     * @param string $text       Corps du message SMS.
     * @throws RuntimeException  Si Wirepick refuse l'envoi ou si la réponse est illisible.
     */
    public function send(string $phoneE164, string $text): void
    {
        // Wirepick n'accepte pas le `+` — on le retire.
        $phone = ltrim($phoneE164, '+');

        // Paramètres de base — from omis si vide (sender ID non enregistré).
        $params = [
            'client'   => $this->clientId,
            'password' => $this->password,
            'phone'    => $phone,
            'text'     => $text,
        ];
        if ($this->from !== '') {
            $params['from'] = $this->from;
        }

        // Wirepick exige POST form-urlencoded pour accepter les sender IDs alphanumériques.
        // GET fonctionne mais rejette les `from` non numériques sur certains comptes.
        $response = Http::timeout(10)
            ->retry(1, 300, throw: false)
            ->asForm()
            ->post(self::API_URL, $params);

        if (! $response->successful()) {
            Log::warning("[wirepick] HTTP {$response->status()} pour {$phoneE164}");
            throw new RuntimeException("Wirepick : erreur HTTP {$response->status()}.");
        }

        // Succès : <messages><sms><msgid>…</msgid><status>NCR</status>…</sms></messages>
        // Erreur  : <sms><error>ERROR: …</error></sms>
        // On détecte l'erreur via la balise <error>, pas via le statut (valeurs variables selon l'opérateur).
        $xml = @simplexml_load_string($response->body());

        // Erreur métier Wirepick (credentials, sender ID, numéro invalide…)
        $errorMsg = $xml ? (string) ($xml->error ?? $xml->sms->error ?? '') : '';
        if ($errorMsg !== '') {
            Log::warning("[wirepick] SMS rejeté pour {$phoneE164}", [
                'error' => $errorMsg,
                'body'  => $response->body(),
            ]);
            throw new RuntimeException("Wirepick : {$errorMsg}");
        }

        // Succès — msgid dans messages->sms->msgid
        $msgid = $xml ? (string) ($xml->sms->msgid ?? '') : '';
        Log::info("[wirepick] SMS envoyé à {$phoneE164}", ['msgid' => $msgid]);
    }
}
