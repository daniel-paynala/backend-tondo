<?php

namespace App\Services;

use App\Contracts\PushNotifier;
use App\Models\Project;
use App\Models\TondoDeviceToken;
use App\Support\FcmSupport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envoi de notifications push via **FCM HTTP v1** (Firebase Cloud Messaging).
 *
 * Remplace OneSignal : FCM (Android) + APNs (iOS, relayé par Firebase) sont
 * gratuits à n'importe quelle échelle. On cible les appareils par leurs
 * **registration tokens** stockés dans {@see TondoDeviceToken} (un message FCM
 * par token). Les tokens morts (device désinstallé) sont purgés à la volée.
 *
 * Authentification : compte de service Google (JSON). On signe un JWT RS256
 * (openssl, aucune dépendance externe), on l'échange contre un access token
 * OAuth2 (mis en cache ~55 min), puis on appelle l'endpoint messages:send.
 *
 * Env vars requises :
 *   FCM_PROJECT_ID       — id du projet Firebase (ex : tonji-xxxx).
 *   FCM_CREDENTIALS_PATH — chemin du JSON de compte de service
 *                          (défaut : storage/app/fcm/service-account.json).
 */
class FcmService implements PushNotifier
{
    /** Endpoint d'envoi FCM HTTP v1 (le {project} est injecté à l'exécution). */
    private const SEND_URL = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    /** Scope OAuth requis pour l'envoi FCM. */
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private string $projectId;
    private string $credentialsPath;

    public function __construct()
    {
        $this->projectId       = (string) config('services.fcm.project_id', '');
        $this->credentialsPath = (string) config('services.fcm.credentials', '');
    }

    /** True si le projet + le fichier de compte de service sont présents. */
    public function estConfigure(): bool
    {
        return $this->projectId !== '' && $this->credentialsPath !== '' && is_file($this->credentialsPath);
    }

    /**
     * Envoie une notification à un ou plusieurs utilisateurs (best-effort).
     */
    public function notify(array $userIds, string $titleFr, string $bodyFr, array $data = []): void
    {
        if (empty($userIds) || ! $this->estConfigure()) {
            return;
        }

        $ids = array_values(array_unique(array_filter($userIds)));
        if (empty($ids)) {
            return;
        }

        // Tous les tokens des appareils de ces utilisateurs (scoping projet).
        $tokens = TondoDeviceToken::query()
            ->where('project_id', Project::tondoId())
            ->whereIn('user_id', $ids)
            ->get();

        if ($tokens->isEmpty()) {
            return;
        }

        $accessToken = $this->accessToken();
        if ($accessToken === null) {
            Log::warning('FCM : access token OAuth indisponible (compte de service invalide ?).');

            return;
        }

        foreach ($tokens as $ligne) {
            $res    = $this->envoyerVersToken($accessToken, $ligne->token, $titleFr, $bodyFr, $data);
            $status = $res['status'] ?? 0;

            if ($status >= 200 && $status < 300) {
                continue; // livré à FCM
            }

            // Token mort (device désinstallé / token expiré) → on le purge.
            if ($this->tokenInvalide($res)) {
                $ligne->delete();
                continue;
            }

            Log::warning('FCM : échec envoi', [
                'status'  => $status,
                'body'    => $res['body'] ?? null,
                'user_id' => $ligne->user_id,
            ]);
        }
    }

    /** Raccourci : notifie un seul utilisateur. */
    public function notifyOne(string $userId, string $titleFr, string $bodyFr, array $data = []): void
    {
        $this->notify([$userId], $titleFr, $bodyFr, $data);
    }

    /**
     * Envoi de diagnostic à un user : renvoie le résultat BRUT par token
     * (statut + corps), sans purger ni avaler. Utilisé par `tonji:test-push`.
     *
     * @return array{configure:bool, auth?:bool, tokens?:int, resultats?:array}
     */
    public function envoyerBrutUser(string $userId, string $titleFr, string $bodyFr): array
    {
        if (! $this->estConfigure()) {
            return ['configure' => false];
        }

        $tokens = TondoDeviceToken::query()
            ->where('project_id', Project::tondoId())
            ->where('user_id', $userId)
            ->get();

        $accessToken = $this->accessToken();
        if ($accessToken === null) {
            return ['configure' => true, 'auth' => false, 'tokens' => $tokens->count()];
        }

        $resultats = [];
        foreach ($tokens as $ligne) {
            $res = $this->envoyerVersToken($accessToken, $ligne->token, $titleFr, $bodyFr, ['type' => 'test_push']);
            $resultats[] = [
                'token'      => substr($ligne->token, 0, 16) . '…',
                'plateforme' => $ligne->plateforme,
                'status'     => $res['status'] ?? 0,
                'body'       => $res['body'] ?? null,
            ];
        }

        return ['configure' => true, 'auth' => true, 'tokens' => $tokens->count(), 'resultats' => $resultats];
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /** Envoie un message FCM v1 à un token donné et renvoie le résultat brut. */
    private function envoyerVersToken(string $accessToken, string $token, string $titleFr, string $bodyFr, array $data): array
    {
        $message = [
            'token'        => $token,
            'notification' => ['title' => $titleFr, 'body' => $bodyFr],
        ];

        // FCM exige des `data` en chaînes de caractères uniquement.
        if (! empty($data)) {
            $message['data'] = FcmSupport::stringifier($data);
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(8)
                ->post(sprintf(self::SEND_URL, $this->projectId), ['message' => $message]);

            return [
                'status' => $response->status(),
                'json'   => $response->json(),
                'body'   => $response->body(),
            ];
        } catch (\Throwable $e) {
            return ['status' => 0, 'json' => null, 'body' => $e->getMessage()];
        }
    }

    /**
     * Un token est considéré mort si FCM répond 404 (NOT_FOUND / UNREGISTERED).
     * On reste conservateur : on ne purge PAS sur 400 (INVALID_ARGUMENT peut
     * venir d'une erreur de payload, pas forcément du token).
     */
    private function tokenInvalide(array $res): bool
    {
        return FcmSupport::tokenInvalide(
            (int) ($res['status'] ?? 0),
            is_array($res['json'] ?? null) ? $res['json'] : null,
        );
    }

    /**
     * Access token OAuth2 (mis en cache jusqu'à ~1 min avant expiration).
     * Signe un JWT RS256 avec la clé privée du compte de service et l'échange
     * contre un access token. Renvoie null si l'authentification échoue.
     */
    private function accessToken(): ?string
    {
        $cache = Cache::get('fcm:access_token');
        if (is_string($cache) && $cache !== '') {
            return $cache;
        }

        $creds = $this->credentials();
        if ($creds === null) {
            return null;
        }

        $tokenUri = $creds['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now      = time();

        $jwtHeader = $this->base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $jwtClaim  = $this->base64url((string) json_encode([
            'iss'   => $creds['client_email'],
            'scope' => self::SCOPE,
            'aud'   => $tokenUri,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $signature = '';
        if (! openssl_sign("{$jwtHeader}.{$jwtClaim}", $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
            Log::warning('FCM : signature JWT impossible (clé privée du compte de service invalide ?).');

            return null;
        }
        $assertion = "{$jwtHeader}.{$jwtClaim}." . $this->base64url($signature);

        try {
            $response = Http::asForm()->timeout(8)->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ]);
        } catch (\Throwable $e) {
            Log::warning('FCM : échange OAuth échoué', ['message' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('FCM : échange OAuth refusé', ['status' => $response->status(), 'body' => $response->body()]);

            return null;
        }

        $accessToken = (string) $response->json('access_token');
        $expiresIn   = (int) $response->json('expires_in', 3600);
        if ($accessToken === '') {
            return null;
        }

        // Marge de 60 s pour ne jamais utiliser un token juste expiré.
        Cache::put('fcm:access_token', $accessToken, max(60, $expiresIn - 60));

        return $accessToken;
    }

    /** Charge et valide le JSON du compte de service. */
    private function credentials(): ?array
    {
        if (! is_file($this->credentialsPath)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($this->credentialsPath), true);
        if (! is_array($json) || ! isset($json['client_email'], $json['private_key'])) {
            Log::warning('FCM : fichier de compte de service illisible ou incomplet.');

            return null;
        }

        return $json;
    }

    /** Encodage base64url (sans padding) pour le JWT — délégué à FcmSupport. */
    private function base64url(string $bin): string
    {
        return FcmSupport::base64url($bin);
    }
}
