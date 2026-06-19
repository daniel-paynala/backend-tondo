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

    /**
     * Initialise le client depuis les variables d'environnement Laravel.
     *
     * Variables requises :
     *   ONESIGNAL_APP_ID       — App ID du tableau de bord OneSignal.
     *   ONESIGNAL_REST_API_KEY — REST API Key (onglet "Keys & IDs" du dashboard).
     *
     * Si l'une des deux est absente, notify() retourne silencieusement sans erreur
     * (on ne bloque jamais le flux métier pour une notification manquante).
     */
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
        // Sortie anticipée si la liste est vide ou si OneSignal n'est pas configuré.
        if (empty($userIds) || empty($this->appId) || empty($this->restApiKey)) {
            return;
        }

        // Dédoublonnage + exclusion des IDs vides ou null.
        $ids = array_values(array_unique(array_filter($userIds)));
        if (empty($ids)) {
            return;
        }

        // Payload OneSignal v2 — ciblage par external_id (= UUID Tondo).
        $payload = [
            'app_id'          => $this->appId,
            // include_aliases avec external_id évite de stocker des player_id en DB.
            'include_aliases' => ['external_id' => $ids],
            'target_channel'  => 'push',
            // Même titre en FR et EN — Tondo est 100% francophone pour l'instant.
            'headings'        => ['fr' => $titleFr, 'en' => $titleFr],
            'contents'        => ['fr' => $bodyFr,  'en' => $bodyFr],
        ];

        // `data` est optionnel : il transporte des métadonnées pour l'app mobile
        // (ex : type d'événement, cagnotte_id, montant) afin de naviguer vers
        // le bon écran au tap de la notification.
        if (! empty($data)) {
            $payload['data'] = $data;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Key {$this->restApiKey}", // REST API Key (pas le client secret OAuth).
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
            // On ne fait jamais échouer le flux métier pour une notification ratée.
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
