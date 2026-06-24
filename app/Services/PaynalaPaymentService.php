<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    /**
     * Initialise le client depuis les variables d'environnement Laravel.
     *
     * - PAYNALA_BASE_URL     : URL de base de l'API (staging par défaut).
     * - PAYNALA_CLIENT_ID    : identifiant OAuth2.
     * - PAYNALA_CLIENT_SECRET: secret OAuth2.
     * - PAYNALA_OPERATOR_KEY : clé opérateur requise pour l'endpoint disburse.
     */
    public function __construct()
    {
        // Le trailing slash est retiré pour éviter les doubles slashes dans les URLs construites.
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
            ->timeout(8)
            ->post("{$this->baseUrl}/create_payment_v2", [
                'request_id' => $requestId,
                'amount'     => $amount,
                'phone'      => $phone,
                'first_name' => $firstName,
                'last_name'  => $lastName,
            ]);

        // On vérifie à la fois le code HTTP et le flag `success` du corps JSON,
        // car l'API peut retourner 200 avec success:false sur certaines erreurs métier.
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
            ->timeout(4)
            ->get("{$this->baseUrl}/payment_status_v2", ['request_id' => $requestId]);

        // 404 = l'API ne connaît pas ce requestId → le paiement n'a jamais existé.
        if ($response->status() === 404) {
            return ['status' => 'FAILED', 'message' => 'Paiement introuvable.'];
        }

        if (! $response->successful()) {
            $msg = $response->json('error.message')
                ?? $response->json('message')
                ?? 'Erreur lors de la vérification du statut.';
            throw new \RuntimeException($msg);
        }

        // Si `data` est absent, on suppose que le paiement est toujours en attente.
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
        $cacheKey     = 'paynala_kyc_'      . $msisdn;
        $typeClientKey = 'paynala_kyc_type_' . $msisdn;

        if (Cache::has($cacheKey)) {
            return true;
        }

        try {
            $token = $this->getToken();

            $response = Http::withToken($token)
                ->timeout(10)
                ->post("{$this->baseUrl}/kyc", ['msisdn' => $msisdn]);

            // Succès confirmé.
            if ($response->json('success') === true && $response->json('status') === 'FOUND') {
                Cache::put($cacheKey, true, now()->addHours(24));

                // Dérive type_client depuis le grade Airtel :
                // SUBS (abonné particulier) et TEMP (enregistrement temporaire)
                // → particulier ; tout autre grade → entreprise.
                $grade      = $response->json('data.grade');
                $typeClient = in_array($grade, ['SUBS', 'TEMP'], true)
                    ? 'particulier'
                    : 'entreprise';
                Cache::put($typeClientKey, $typeClient, now()->addHours(24));

                return true;
            }

            // Échec confirmé : le numéro n'a pas de compte Airtel Money.
            if ($response->json('success') === false && $response->json('status') === 'FAILED') {
                return false;
            }

            // Tout autre cas (merchant inactif, réponse inattendue…) :
            // service indisponible → on ne bloque pas.
            Log::warning('[paynala] checkKyc réponse inattendue', [
                'msisdn' => $msisdn,
                'status' => $response->status(),
                'body'   => $response->json() ?? $response->body(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error('[paynala] checkKyc exception', [
                'msisdn' => $msisdn,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Vérifie le KYC Airtel et retourne les données complètes du compte,
     * notamment le nom et le prénom pour l'auto-complétion du formulaire.
     *
     * Format de retour :
     *   ['ok' => true, 'nom' => 'DOVI AKON', 'prenom' => 'Daniel',
     *    'grade' => 'SUBS', 'type_client' => 'particulier']
     *
     * Retourne null si le service est indisponible (timeout, erreur réseau).
     * Retourne ['ok' => false] si le numéro n'a pas de compte Airtel Money.
     *
     * Met en cache nom/prénom 24 h (même durée que checkKyc) pour éviter
     * de rappeler l'API lors du verify-otp.
     *
     * @param  string $msisdn  Numéro local Gabon format 0XXXXXXXX ou E.164.
     * @return array{ok:bool,nom?:string,prenom?:string,grade?:string,type_client?:string}|null
     */
    public function checkKycData(string $msisdn): ?array
    {
        $cacheKey     = 'paynala_kyc_'      . $msisdn;
        $nomKey       = 'paynala_kyc_nom_'  . $msisdn;
        $typeClientKey = 'paynala_kyc_type_' . $msisdn;

        // Cache hit : toutes les données ont déjà été récupérées lors d'un appel précédent
        if (Cache::has($cacheKey) && Cache::has($nomKey)) {
            $cached = Cache::get($nomKey);   // tableau ['nom', 'prenom', 'grade']
            return [
                'ok'          => true,
                'nom'         => $cached['nom']   ?? '',
                'prenom'      => $cached['prenom'] ?? '',
                'grade'       => $cached['grade']  ?? '',
                'type_client' => Cache::get($typeClientKey, 'particulier'),
            ];
        }

        try {
            $token = $this->getToken();

            $response = Http::withToken($token)
                ->timeout(10)
                ->post("{$this->baseUrl}/kyc", ['msisdn' => $msisdn]);

            // Compte trouvé et actif
            if ($response->json('success') === true && $response->json('status') === 'FOUND') {
                $grade      = $response->json('data.grade') ?? 'SUBS';
                $nom        = $response->json('data.last_name')  ?? '';
                $prenom     = $response->json('data.first_name') ?? '';
                $typeClient = in_array($grade, ['SUBS', 'TEMP'], true) ? 'particulier' : 'entreprise';

                // Mise en cache 24 h — cohérente avec checkKyc()
                Cache::put($cacheKey,      true,                                  now()->addHours(24));
                Cache::put($nomKey,        ['nom' => $nom, 'prenom' => $prenom, 'grade' => $grade], now()->addHours(24));
                Cache::put($typeClientKey, $typeClient,                            now()->addHours(24));

                return compact('nom', 'prenom', 'grade', 'typeClient') + ['ok' => true, 'type_client' => $typeClient];
            }

            // Numéro sans compte Airtel Money
            if ($response->json('success') === false && $response->json('status') === 'FAILED') {
                return ['ok' => false];
            }

            // Réponse inattendue (merchant inactif, format inconnu…) → service indisponible
            Log::warning('[paynala] checkKycData réponse inattendue', [
                'msisdn' => $msisdn,
                'status' => $response->status(),
                'body'   => $response->json() ?? $response->body(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error('[paynala] checkKycData exception', [
                'msisdn' => $msisdn,
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Retourne le type_client dérivé du grade KYC mis en cache lors du sign-up.
     * null si le KYC n'a pas encore été appelé pour ce numéro.
     */
    public function resolveTypeClientFromKyc(string $msisdn): ?string
    {
        return Cache::get('paynala_kyc_type_' . $msisdn);
    }

    /**
     * Décaissement Airtel Money (payout) vers un bénéficiaire.
     *
     * @param  string $idempotencyKey  Clé unique (ex : TONDOPAYOUT-XXXXXXXXX) — évite les doublons.
     * @param  int    $amount          Montant en XAF.
     * @param  string $msisdn          Numéro local 9 chiffres (ex : 074577473).
     * @param  string $reference       Courte référence lisible.
     * @param  string $type            'B2C' (particulier) ou 'B2B' (entreprise) — requis par Paynala.
     * @return array  Données retournées par l'API (airtel_money_id, status, …).
     * @throws \RuntimeException  Si l'API retourne une erreur.
     */
    public function disburse(
        string $idempotencyKey,
        int    $amount,
        string $msisdn,
        string $reference,
        string $type = 'B2C',
    ): array {
        // Toujours un token frais pour disburse : l'endpoint est plus strict
        // que KYC/payment et rejette les tokens mis en cache trop longtemps.
        Cache::forget('paynala_oauth_token');
        $token = $this->getToken();

        $response = Http::withToken($token)
            ->withHeaders(['x-operator-key' => $this->operatorKey])
            ->timeout(15)
            ->post("{$this->baseUrl}/disburse", [
                'msisdn'          => $msisdn,
                'amount'          => $amount,
                'reference'       => $reference,
                'idempotency_key' => $idempotencyKey,
                'type'            => $type,
            ]);

        if (! $response->successful() || ! ($response->json('success') ?? false)) {
            Log::error('[Paynala disburse] échec', [
                'status'   => $response->status(),
                'body'     => $response->body(),
                'msisdn'   => $msisdn,
                'amount'   => $amount,
                'type'     => $type,
            ]);

            $msg = $response->json('error.message')
                ?? $response->json('error')
                ?? $response->json('message')
                ?? $response->json('detail')
                ?? ('Erreur Paynala disburse (HTTP ' . $response->status() . '): ' . $response->body());
            throw new \RuntimeException($msg);
        }

        return $response->json();
    }

    /**
     * Résout le type de décaissement (B2C / B2B) pour un numéro donné.
     *
     * Ordre de résolution :
     *  1. Compte Tondo existant → type_client (particulier = B2C, entreprise = B2B).
     *  2. Cache KYC existant (grade Airtel mémorisé lors du sign-up).
     *  3. Appel KYC live si rien en cache.
     *  4. Défaut B2C si KYC indisponible.
     *
     * @param  string      $msisdnLocal  Numéro local 9 chiffres (ex : 077730634).
     * @param  string|null $msisdnE164   Numéro E.164 correspondant (ex : +24177730634).
     * @param  string|null $userId       UUID du compte Tondo du bénéficiaire, si connu.
     */
    public function resolveDisburseType(
        string  $msisdnLocal,
        ?string $msisdnE164 = null,
        ?string $userId     = null,
    ): string {
        // 1. Compte Tondo → type_client persisté en base.
        if ($userId) {
            $typeClient = \Illuminate\Support\Facades\DB::table('users')
                ->where('id', $userId)
                ->value('type_client');

            if ($typeClient) {
                return $typeClient === 'entreprise' ? 'B2B' : 'B2C';
            }
        }

        // 2. Cache KYC issu du grade Airtel (mémorisé à la vérification du numéro).
        $cacheType = Cache::get('paynala_kyc_type_' . $msisdnLocal)
            ?? ($msisdnE164 ? Cache::get('paynala_kyc_type_' . $msisdnE164) : null);

        if ($cacheType) {
            return $cacheType === 'entreprise' ? 'B2B' : 'B2C';
        }

        // 3. Appel KYC live pour résoudre le grade Airtel.
        try {
            $this->checkKyc($msisdnLocal);
            $cacheType = Cache::get('paynala_kyc_type_' . $msisdnLocal);
            if ($cacheType) {
                return $cacheType === 'entreprise' ? 'B2B' : 'B2C';
            }
        } catch (\Throwable) {
            // KYC indisponible — on ne bloque pas le payout.
        }

        // 4. Défaut sécurisé : particulier.
        return 'B2C';
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Récupère (ou régénère) un token OAuth2 client_credentials depuis l'API Paynala.
     *
     * Le token est mis en cache 160 s. L'API l'expire à 170 s côté serveur,
     * ce qui laisse une marge de 10 s pour éviter toute utilisation d'un
     * token expiré en transit.
     *
     * @throws \RuntimeException  Si l'API d'authentification échoue.
     */
    private function getToken(): string
    {
        // Cache 160 s < expiration 170 s → jamais de token expiré en transit.
        return Cache::remember('paynala_oauth_token', 160, function () {
            $response = Http::timeout(4)
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
