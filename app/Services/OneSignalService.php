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
 *   ONESIGNAL_REST_API_KEY — Clé API REST (dashboard OneSignal > Keys & IDs).
 *                            Nouveau format : commence par `os_v2_app_…`.
 *                            Les anciennes clés hexadécimales sont dépréciées
 *                            (l'UI de création a été retirée) — si les pushs
 *                            échouent en 401/403, régénérer une clé `os_v2_app_`.
 */
class OneSignalService
{
    private const API_URL = 'https://api.onesignal.com/notifications';

    private string $appId;
    private string $restApiKey;

    /**
     * Initialise le client depuis les variables d'environnement Laravel.
     *
     * Si l'une des deux clés est absente, notify() retourne silencieusement
     * (on ne bloque jamais le flux métier pour une notification manquante).
     */
    public function __construct()
    {
        $this->appId      = (string) config('services.onesignal.app_id', '');
        $this->restApiKey = (string) config('services.onesignal.rest_api_key', '');
    }

    /** True si l'App ID et la clé REST sont bien présents. */
    public function estConfigure(): bool
    {
        return $this->appId !== '' && $this->restApiKey !== '';
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
        if (empty($userIds) || ! $this->estConfigure()) {
            return;
        }

        // Dédoublonnage + exclusion des IDs vides ou null.
        $ids = array_values(array_unique(array_filter($userIds)));
        if (empty($ids)) {
            return;
        }

        // Envoie et journalise le résultat (y compris les échecs silencieux : un
        // HTTP 200 peut cacher « 0 destinataire » si l'external_id n'est pas abonné).
        $resultat = $this->envoyer($ids, $titleFr, $bodyFr, $data);
        $this->journaliser($resultat, $ids);
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

    /**
     * Envoie un push et retourne le résultat BRUT (statut HTTP + corps décodé),
     * sans rien journaliser ni avaler. Réservé au diagnostic (commande
     * `tonji:test-push`) : permet de voir exactement ce que répond OneSignal
     * (401/403 = clé invalide, `invalid_aliases` = device non abonné, etc.).
     *
     * @return array{configure:bool, status?:int, json?:mixed, body?:string, error?:string}
     */
    public function envoyerBrut(
        array  $userIds,
        string $titleFr,
        string $bodyFr,
        array  $data = [],
    ): array {
        if (! $this->estConfigure()) {
            return ['configure' => false];
        }

        $ids = array_values(array_unique(array_filter($userIds)));

        try {
            $response = Http::withHeaders($this->entetes())
                ->timeout(8)
                ->post(self::API_URL, $this->construirePayload($ids, $titleFr, $bodyFr, $data));

            return [
                'configure' => true,
                'status'    => $response->status(),
                'json'      => $response->json(),
                'body'      => $response->body(),
            ];
        } catch (\Throwable $e) {
            return ['configure' => true, 'error' => $e->getMessage()];
        }
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /** Entêtes HTTP — `Key <clé>` est le format attendu par l'API v2 (pas `Basic`). */
    private function entetes(): array
    {
        return [
            'Authorization' => "Key {$this->restApiKey}",
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Construit le payload OneSignal v2 — ciblage par external_id (= UUID Tondo).
     * `include_aliases` + `target_channel` remplacent l'ancien
     * `include_external_user_ids` (déprécié) et évitent de stocker des player_id.
     */
    private function construirePayload(array $ids, string $titleFr, string $bodyFr, array $data): array
    {
        $payload = [
            'app_id'          => $this->appId,
            'include_aliases' => ['external_id' => array_values($ids)],
            'target_channel'  => 'push',
            // Même titre/corps en FR et EN — Tondo est 100% francophone pour l'instant
            // (mais OneSignal exige la clé 'en' comme langue par défaut).
            'headings'        => ['fr' => $titleFr, 'en' => $titleFr],
            'contents'        => ['fr' => $bodyFr,  'en' => $bodyFr],
        ];

        // `data` transporte des métadonnées pour l'app (type d'événement, ids…)
        // afin de router vers le bon écran au tap de la notification.
        if (! empty($data)) {
            $payload['data'] = $data;
        }

        return $payload;
    }

    /** Envoie le push (best-effort) et renvoie un résultat structuré. */
    private function envoyer(array $ids, string $titleFr, string $bodyFr, array $data): array
    {
        try {
            $response = Http::withHeaders($this->entetes())
                ->timeout(8)
                ->post(self::API_URL, $this->construirePayload($ids, $titleFr, $bodyFr, $data));

            return [
                'ok'     => $response->successful(),
                'status' => $response->status(),
                'json'   => $response->json(),
                'body'   => $response->body(),
            ];
        } catch (\Throwable $e) {
            // On ne fait jamais échouer le flux métier pour une notification ratée.
            return ['ok' => false, 'status' => 0, 'json' => null, 'body' => $e->getMessage()];
        }
    }

    /**
     * Journalise le résultat. On logge dans DEUX cas trop souvent silencieux :
     *  – HTTP non-2xx (clé invalide, payload rejeté…) ;
     *  – HTTP 200 mais `recipients = 0` ou présence d'`errors` (external_id non
     *    abonné / `invalid_aliases`) → le push n'a atteint personne.
     */
    private function journaliser(array $resultat, array $ids): void
    {
        $json       = is_array($resultat['json'] ?? null) ? $resultat['json'] : [];
        $recipients = $json['recipients'] ?? null;
        $errors     = $json['errors'] ?? null;

        if (empty($resultat['ok'])) {
            Log::warning('OneSignal notify : échec HTTP', [
                'status'   => $resultat['status'] ?? null,
                'body'     => $resultat['body'] ?? null,
                'user_ids' => $ids,
            ]);
            return;
        }

        if ($recipients === 0 || ! empty($errors)) {
            Log::warning('OneSignal notify : 0 destinataire réel (external_id non abonné ?)', [
                'recipients' => $recipients,
                'errors'     => $errors,
                'user_ids'   => $ids,
            ]);
        }
    }
}
