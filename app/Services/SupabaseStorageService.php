<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Accès au **Supabase Storage** (bucket PRIVÉ) pour les pièces des associations.
 *
 * Utilise l'API HTTP Storage de Supabase avec la clé `service_role` (bypass
 * RLS) — même URL/clé que le reste du backend (cf. config/services.php).
 * Aucun fichier n'est stocké sur le disque du serveur applicatif : les pièces
 * vivent dans le bucket managé (persistant, sauvegardé, joignable par le
 * dashboard via des URLs signées).
 *
 * Les objets sont privés : on ne sert JAMAIS d'URL publique, uniquement des
 * **URLs signées temporaires**.
 */
class SupabaseStorageService
{
    private string $url;
    private string $key;
    private string $bucket;

    public function __construct()
    {
        $this->url    = rtrim((string) config('services.supabase.url'), '/');
        $this->key    = (string) config('services.supabase.service_role');
        $this->bucket = (string) config('services.supabase.bucket');
    }

    /**
     * Téléverse (ou remplace, via x-upsert) un objet dans le bucket.
     *
     * @param  string  $path      Chemin relatif dans le bucket (ex : associations/{id}/recepisse.pdf).
     * @param  string  $contents  Contenu binaire du fichier.
     * @param  string  $mime      Type MIME.
     */
    public function upload(string $path, string $contents, string $mime): void
    {
        $res = Http::withToken($this->key)
            ->withHeaders([
                'apikey'       => $this->key,
                'x-upsert'     => 'true',        // écrase si le chemin existe déjà
                'Content-Type' => $mime,
            ])
            ->withBody($contents, $mime)
            ->post("{$this->url}/storage/v1/object/{$this->bucket}/{$path}");

        if ($res->failed()) {
            throw new RuntimeException(
                "Upload Supabase Storage échoué ({$res->status()}) : " . $res->body()
            );
        }
    }

    /**
     * Génère une URL signée temporaire pour lire un objet privé.
     *
     * @param  string  $path       Chemin de l'objet dans le bucket.
     * @param  int     $expiresIn  Durée de validité en secondes (défaut 1 h).
     * @return string              URL absolue signée.
     */
    public function signedUrl(string $path, int $expiresIn = 3600): string
    {
        $res = Http::withToken($this->key)
            ->withHeaders(['apikey' => $this->key])
            ->post("{$this->url}/storage/v1/object/sign/{$this->bucket}/{$path}", [
                'expiresIn' => $expiresIn,
            ]);

        if ($res->failed()) {
            throw new RuntimeException(
                "Génération d'URL signée échouée ({$res->status()}) : " . $res->body()
            );
        }

        // L'API renvoie un chemin relatif ("/object/sign/...&token=...").
        $signed = $res->json('signedURL') ?? $res->json('signedUrl') ?? '';
        return "{$this->url}/storage/v1{$signed}";
    }

    /**
     * Lit le contenu brut d'un objet, ou null s'il est introuvable.
     *
     * Sert aux fichiers que l'API rediffuse elle-même plutôt que de signer une
     * URL par affichage — le logo d'un marchand, lu à chaque ouverture du
     * carnet. Le binaire est renvoyé tel quel, l'appelant pose les entêtes.
     *
     * @return array{contenu: string, mime: string}|null
     */
    public function lire(string $path): ?array
    {
        $res = Http::withToken($this->key)
            ->withHeaders(['apikey' => $this->key])
            ->get("{$this->url}/storage/v1/object/{$this->bucket}/{$path}");

        if ($res->failed()) {
            // Un objet manquant n'est pas une panne : une fiche peut porter un
            // chemin dont le fichier a disparu. L'appelant rendra un 404.
            return null;
        }

        return [
            'contenu' => $res->body(),
            'mime'    => $res->header('Content-Type') ?: 'application/octet-stream',
        ];
    }

    /**
     * Supprime un objet (best-effort — n'interrompt pas le flux appelant).
     */
    public function delete(string $path): void
    {
        try {
            Http::withToken($this->key)
                ->withHeaders(['apikey' => $this->key])
                ->delete("{$this->url}/storage/v1/object/{$this->bucket}/{$path}");
        } catch (\Throwable) {
            // best-effort
        }
    }
}
