<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envoie des messages WhatsApp sortants via l'API REST Twilio.
 *
 * Utilisé pour les notifications proactives (confirmation de paiement,
 * expiration de session, reçu PDF joint). Toute communication sortante
 * du bot passe par cette classe plutôt que par la réponse TwiML synchrone.
 *
 * Configuration requise dans config/services.php (clés Twilio WhatsApp) :
 *   services.twilio.wa_account_sid   → Account SID Twilio
 *   services.twilio.wa_auth_token    → Auth token Twilio
 *   services.twilio.wa_number        → Numéro expéditeur au format "whatsapp:+14155238886"
 */
class TwilioSenderService
{
    /** Account SID Twilio (identifiant de compte). */
    private string $accountSid;

    /** Token d'authentification Twilio (secret). */
    private string $authToken;

    /**
     * Numéro WhatsApp expéditeur au format Twilio (ex : whatsapp:+14155238886).
     * Correspond au sandbox Twilio ou au numéro approuvé en production.
     */
    private string $from;

    /**
     * Lit les credentials Twilio depuis la configuration Laravel.
     * Le numéro "from" est déjà préfixé "whatsapp:" dans la config.
     */
    public function __construct()
    {
        $this->accountSid = config('services.twilio.wa_account_sid');
        $this->authToken  = config('services.twilio.wa_auth_token');
        $this->from       = config('services.twilio.wa_number'); // whatsapp:+14155238886
    }

    /**
     * Envoie un message texte simple sans pièce jointe.
     *
     * @param  string $to       Numéro E.164 du destinataire (ex : +24177123456)
     *                          Le préfixe "whatsapp:" est ajouté automatiquement.
     * @param  string $message  Corps du message WhatsApp (texte brut ou formatage *gras*).
     * @return bool             true si l'API retourne un code 2xx, false sinon.
     */
    public function envoyer(string $to, string $message): bool
    {
        return $this->call($to, $message, null);
    }

    /**
     * Envoie un message texte avec un PDF en pièce jointe (champ MediaUrl Twilio).
     *
     * Le PDF doit être hébergé sur une URL publiquement accessible (https).
     * Twilio récupère le fichier et le transfère via WhatsApp comme document.
     *
     * @param  string $to       Numéro E.164 du destinataire
     * @param  string $message  Corps du message (accompagne le PDF)
     * @param  string $pdfUrl   URL publique du PDF (ex : https://exemple.ga/receipts/xxx.pdf)
     * @return bool             true si succès, false sinon
     */
    public function envoyerAvecPdf(string $to, string $message, string $pdfUrl): bool
    {
        return $this->call($to, $message, $pdfUrl);
    }

    // ── Privé ─────────────────────────────────────────────────────────────────

    /**
     * Effectue l'appel HTTP POST vers l'API Messages de Twilio.
     *
     * Gère à la fois les envois simples et avec media (MediaUrl optionnel).
     * En cas d'erreur réseau ou de réponse non-2xx, log l'anomalie et retourne false
     * sans propager l'exception (le bot continue de fonctionner).
     *
     * @param  string      $to        Numéro E.164 du destinataire
     * @param  string      $message   Corps du message
     * @param  string|null $mediaUrl  URL du PDF joint, ou null si message texte seul
     * @return bool                   true si la requête aboutit, false sinon
     */
    private function call(string $to, string $message, ?string $mediaUrl): bool
    {
        // Ajouter le préfixe "whatsapp:" si absent (Twilio l'exige pour le canal WA)
        $toFormatted = str_starts_with($to, 'whatsapp:') ? $to : "whatsapp:{$to}";

        $payload = [
            'To'   => $toFormatted,
            'From' => $this->from,
            'Body' => $message,
        ];

        // Ajouter l'URL media uniquement si une pièce jointe est demandée
        if ($mediaUrl) {
            $payload['MediaUrl'] = $mediaUrl;
        }

        try {
            $response = Http::withBasicAuth($this->accountSid, $this->authToken)
                ->asForm()   // Twilio attend du application/x-www-form-urlencoded
                ->post(
                    "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json",
                    $payload,
                );

            if ($response->successful()) {
                return true;
            }

            // Réponse reçue mais code HTTP non-2xx (ex : 400 numéro invalide, 429 rate limit)
            Log::warning('TwilioSenderService: réponse non-2xx', [
                'status' => $response->status(),
                'body'   => $response->body(),
                'to'     => $to,
            ]);
            return false;
        } catch (\Throwable $e) {
            // Erreur réseau ou exception inattendue — ne pas propager pour ne pas bloquer le bot
            Log::error('TwilioSenderService: exception', [
                'message' => $e->getMessage(),
                'to'      => $to,
            ]);
            return false;
        }
    }
}
