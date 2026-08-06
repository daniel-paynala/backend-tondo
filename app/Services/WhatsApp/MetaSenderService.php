<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Contracts\WhatsAppSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envoie des messages WhatsApp sortants via la Graph API de Meta (Cloud API).
 *
 * Implémentation Meta du contrat {@see WhatsAppSender}. Contrairement à Twilio,
 * Meta ne permet pas de répondre en synchrone au webhook : toute réponse (y
 * compris celle du bot au message entrant) part par un appel REST à la Graph
 * API. Cette classe centralise ces appels.
 *
 * Configuration requise (config/services.php → services.whatsapp.meta) :
 *   token           → access token permanent (System User)
 *   phone_number_id → Phone Number ID du numéro expéditeur
 *   graph_version   → version de la Graph API (ex : v21.0)
 *
 * Docs : https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages
 */
class MetaSenderService implements WhatsAppSender
{
    /** Access token permanent utilisé en Bearer sur la Graph API. */
    private string $token;

    /** Phone Number ID du numéro expéditeur (identifiant, pas le numéro). */
    private string $phoneNumberId;

    /** Version de la Graph API (ex : v21.0). */
    private string $graphVersion;

    /** Lit les credentials Meta depuis la configuration Laravel. */
    public function __construct()
    {
        $this->token         = (string) config('services.whatsapp.meta.token');
        $this->phoneNumberId = (string) config('services.whatsapp.meta.phone_number_id');
        $this->graphVersion  = (string) config('services.whatsapp.meta.graph_version', 'v21.0');
    }

    /**
     * Envoie un message texte simple (type "text").
     *
     * @param  string $to       Numéro E.164 du destinataire (ex : +24177123456).
     * @param  string $message  Corps du message.
     * @return bool             true si l'API répond 2xx, false sinon.
     */
    public function envoyer(string $to, string $message): bool
    {
        return $this->call($to, [
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $message],
        ]);
    }

    /**
     * Envoie un document PDF (type "document") avec le texte en légende.
     *
     * Meta télécharge le fichier depuis l'URL publique fournie (`link`) et
     * l'envoie en pièce jointe.
     *
     * @param  string $to       Numéro E.164 du destinataire.
     * @param  string $message  Légende accompagnant le PDF.
     * @param  string $pdfUrl   URL publique https du PDF.
     * @return bool             true si succès, false sinon.
     */
    public function envoyerAvecPdf(string $to, string $message, string $pdfUrl): bool
    {
        return $this->call($to, [
            'type'     => 'document',
            'document' => [
                'link'     => $pdfUrl,
                'filename' => 'recu.pdf',
                'caption'  => $message,
            ],
        ]);
    }

    // ── Privé ─────────────────────────────────────────────────────────────────

    /**
     * Effectue l'appel POST vers l'endpoint /messages de la Graph API.
     *
     * Le corps commun (messaging_product + destinataire) est fusionné avec la
     * charge utile spécifique au type de message. Toute erreur est loggée sans
     * être propagée, pour ne pas interrompre le bot ou le scheduler.
     *
     * @param  string              $to       Numéro E.164 du destinataire.
     * @param  array<string,mixed> $payload  Fragment de charge (type + contenu).
     * @return bool                          true si la requête aboutit, false sinon.
     */
    private function call(string $to, array $payload): bool
    {
        // Meta attend le numéro en format international sans le "+" ni "whatsapp:".
        $destinataire = ltrim(str_replace('whatsapp:', '', $to), '+');

        $url = "https://graph.facebook.com/{$this->graphVersion}/{$this->phoneNumberId}/messages";

        $corps = array_merge([
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $destinataire,
        ], $payload);

        try {
            $response = Http::withToken($this->token)
                ->asJson()
                ->post($url, $corps);

            if ($response->successful()) {
                return true;
            }

            // Réponse reçue mais code non-2xx (ex : 400 numéro invalide, 401 token, 429 rate limit).
            Log::warning('MetaSenderService: réponse non-2xx', [
                'status' => $response->status(),
                'body'   => $response->body(),
                'to'     => $to,
            ]);
            return false;
        } catch (\Throwable $e) {
            // Erreur réseau ou exception inattendue — ne pas propager.
            Log::error('MetaSenderService: exception', [
                'message' => $e->getMessage(),
                'to'      => $to,
            ]);
            return false;
        }
    }
}
