<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client vers l'API REST OneSignal v2.
 *
 * Cible les utilisateurs par leur `external_id` = UUID Tondo du user.
 * Le SDK mobile appelle `OneSignal.login(userId)` au démarrage —
 * OneSignal fait lui-même le mapping external_id ↔ subscription_id ;
 * on n'a pas besoin de stocker de player_id en DB.
 *
 * Comptes light (`compte_type='light'`) ignorés : pas de device enregistré.
 *
 * Env vars requises :
 *   ONESIGNAL_APP_ID       — App ID (dashboard OneSignal)
 *   ONESIGNAL_REST_API_KEY — REST API Key (dashboard OneSignal > Keys & IDs)
 */
class OneSignalService
{
    private const API_URL = 'https://api.onesignal.com/notifications';

    private string $appId;
    private string $restApiKey;

    public function __construct()
    {
        $this->appId      = (string) config('services.onesignal.app_id', '');
        $this->restApiKey = (string) config('services.onesignal.rest_api_key', '');
    }

    /**
     * Envoie une notification push à un ou plusieurs utilisateurs Tondo.
     *
     * @param  string[] $userIds  UUIDs Tondo (external_id dans OneSignal).
     *                            Vide ou null → rien n'est envoyé.
     * @param  string   $titleFr  Titre en français.
     * @param  string   $bodyFr   Corps en français.
     * @param  array    $data     Données custom transmises à l'app (ex: type, cagnotte_id).
     */
    public function notify(
        array  $userIds,
        string $titleFr,
        string $bodyFr,
        array  $data = [],
    ): void {
        if (empty($userIds) || empty($this->appId) || empty($this->restApiKey)) {
            return;
        }

        // Dédoublonnage + exclusion des IDs vides
        $ids = array_values(array_unique(array_filter($userIds)));
        if (empty($ids)) {
            return;
        }

        $payload = [
            'app_id'           => $this->appId,
            'include_aliases'  => ['external_id' => $ids],
            'target_channel'   => 'push',
            'headings'         => ['fr' => $titleFr, 'en' => $titleFr],
            'contents'         => ['fr' => $bodyFr,  'en' => $bodyFr],
        ];

        if (! empty($data)) {
            $payload['data'] = $data;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Key {$this->restApiKey}",
                'Content-Type'  => 'application/json',
            ])->timeout(8)->post(self::API_URL, $payload);

            if (! $response->successful()) {
                Log::warning('OneSignal notify failed', [
                    'status'   => $response->status(),
                    'body'     => $response->body(),
                    'user_ids' => $ids,
                ]);
            }
        } catch (\Throwable $e) {
            // On ne fait jamais échouer le flow métier pour une notif ratée.
            Log::error('OneSignal exception', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Raccourci : notifie un seul utilisateur.
     */
    public function notifyOne(
        string $userId,
        string $titleFr,
        string $bodyFr,
        array  $data = [],
    ): void {
        $this->notify([$userId], $titleFr, $bodyFr, $data);
    }
}
