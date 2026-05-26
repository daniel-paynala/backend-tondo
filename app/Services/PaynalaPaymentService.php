<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Client HTTP vers l'API de paiement Paynala (Airtel Money Gabon).
 *
 * OAuth2 client_credentials — token mis en cache 160 s (expire à 170 s côté API).
 * Env vars requises : PAYNALA_CLIENT_ID, PAYNALA_CLIENT_SECRET.
 * Base URL via PAYNALA_BASE_URL (défaut = staging testapi.paynala.com).
 */
class PaynalaPaymentService
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $operatorKey;

    public function __construct()
    {
        $this->baseUrl      = rtrim(config('services.paynala.base_url', 'https://testapi.paynala.com/functions/v1'), '/');
        $this->clientId     = (string) config('services.paynala.client_id', '');
        $this->clientSecret = (string) config('services.paynala.client_secret', '');
        $this->operatorKey  = (string) config('services.paynala.operator_key', '');
    }

    /**
     * Initie un paiement Airtel Money.
     *
     * @param  string $requestId  Identifiant unique alphanumérique (4-64 chars, pas de tirets).
     * @param  int    $amount     Montant en XAF (brut, frais inclus).
     * @param  string $phone      Numéro local 9 chiffres (ex : 074577473).
     * @param  string $firstName  Prénom du payeur (facultatif mais recommandé).
     * @param  string $lastName   Nom du payeur (facultatif mais recommandé).
     * @return array  Données retournées par l'API (paymentId, requestId, status …).
     * @throws \RuntimeException  Si l'API retourne une erreur.
     */
    public function createPayment(
        string $requestId,
        int    $amount,
        string $phone,
        string $firstName = '',
        string $lastName  = '',
    ): array {
        $token = $this->getToken();

        $response = Http::withToken($token)
            ->timeout(15)
            ->post("{$this->baseUrl}/create_payment_v2", [
                'request_id' => $requestId,
                'amount'     => $amount,
                'phone'      => $phone,
                'first_name' => $firstName,
                'last_name'  => $lastName,
            ]);

        if (! $response->successful() || ! ($response->json('success') ?? false)) {
            $msg = $response->json('error.message')
                ?? $response->json('message')
                ?? 'Erreur lors de l\'initiation du paiement Airtel Money.';
            throw new \RuntimeException($msg);
        }

        return $response->json('data', []);
    }

    /**
     * Interroge le statut d'un paiement.
     *
     * @return array  Contient au minimum ['status' => 'PENDING'|'SUCCESS'|'FAILED'].
     * @throws \RuntimeException  Si l'API retourne une erreur non-404.
     */
    public function checkStatus(string $requestId): array
    {
        $token = $this->getToken();

        $response = Http::withToken($token)
            ->timeout(10)
            ->get("{$this->baseUrl}/payment_status_v2", ['request_id' => $requestId]);

        if ($response->status() === 404) {
            return ['status' => 'FAILED', 'message' => 'Paiement introuvable.'];
        }

        if (! $response->successful()) {
            $msg = $response->json('error.message')
                ?? $response->json('message')
                ?? 'Erreur lors de la vérification du statut.';
            throw new \RuntimeException($msg);
        }

        return $response->json('data', ['status' => 'PENDING']);
    }

    /**
     * Vérifie si un numéro Airtel possède un compte Mobile Money actif.
     *
     * @param  string $msisdn  Numéro local avec zéro initial (ex : "077730634").
     * @return bool|null
     *   true  = compte actif confirmé → autoriser
     *   false = numéro introuvable confirmé (success:false, status:FAILED) → bloquer
     *   null  = service indisponible (merchant inactif, timeout…) → laisser passer
     */
    public function checkKyc(string $msisdn): bool|null
    {
        $cacheKey = 'paynala_kyc_' . $msisdn;

        if (Cache::has($cacheKey)) {
            return true;
        }

        try {
            $token = $this->getToken();

            $response = Http::withToken($token)
                ->withHeaders(['x-operator-key' => $this->operatorKey])
                ->timeout(10)
                ->post("{$this->baseUrl}/kyc", ['msisdn' => $msisdn]);

            \Illuminate\Support\Facades\Log::info('[PaynalaKYC] msisdn=' . $msisdn
                . ' status=' . $response->status()
                . ' body=' . $response->body());

            // Succès confirmé.
            if ($response->json('success') === true && $response->json('status') === 'FOUND') {
                Cache::put($cacheKey, true, now()->addHours(24));
                return true;
            }

            // Échec confirmé : le numéro n'a pas de compte Airtel Money.
            if ($response->json('success') === false && $response->json('status') === 'FAILED') {
                return false;
            }

            // Tout autre cas (merchant inactif, réponse inattendue…) :
            // service indisponible → on ne bloque pas.
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────

    private function getToken(): string
    {
        // Cache 160 s < expiration 170 s → jamais de token expiré en transit.
        return Cache::remember('paynala_oauth_token', 160, function () {
            $response = Http::timeout(10)
                ->post("{$this->baseUrl}/oauth_token_v2", [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]);

            if (! $response->successful()) {
                $msg = $response->json('error_description')
                    ?? $response->json('message')
                    ?? 'Impossible d\'obtenir un token Paynala.';
                throw new \RuntimeException($msg);
            }

            return (string) $response->json('access_token');
        });
    }
}
