<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envoie des messages WhatsApp sortants via l'API REST Twilio.
 * Utilisé pour les notifications proactives (confirmation de paiement, timeout).
 */
class TwilioSenderService
{
    private string $accountSid;
    private string $authToken;
    private string $from;

    public function __construct()
    {
        $this->accountSid = config('services.twilio.wa_account_sid');
        $this->authToken  = config('services.twilio.wa_auth_token');
        $this->from       = config('services.twilio.wa_number'); // whatsapp:+14155238886
    }

    /**
     * Envoie un message texte.
     *
     * @param  string $to  Numéro E.164 (ex: +22177...)  — le préfixe "whatsapp:" est ajouté ici
     */
    public function envoyer(string $to, string $message): bool
    {
        return $this->call($to, $message, null);
    }

    /**
     * Envoie un message texte avec un PDF en pièce jointe (Media).
     *
     * @param  string $to      Numéro E.164
     * @param  string $message Corps du message
     * @param  string $pdfUrl  URL publique du PDF
     */
    public function envoyerAvecPdf(string $to, string $message, string $pdfUrl): bool
    {
        return $this->call($to, $message, $pdfUrl);
    }

    // ── Privé ─────────────────────────────────────────────────────────────────

    private function call(string $to, string $message, ?string $mediaUrl): bool
    {
        $toFormatted = str_starts_with($to, 'whatsapp:') ? $to : "whatsapp:{$to}";

        $payload = [
            'To'   => $toFormatted,
            'From' => $this->from,
            'Body' => $message,
        ];

        if ($mediaUrl) {
            $payload['MediaUrl'] = $mediaUrl;
        }

        try {
            $response = Http::withBasicAuth($this->accountSid, $this->authToken)
                ->asForm()
                ->post(
                    "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json",
                    $payload,
                );

            if ($response->successful()) {
                return true;
            }

            Log::warning('TwilioSenderService: réponse non-2xx', [
                'status' => $response->status(),
                'body'   => $response->body(),
                'to'     => $to,
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::error('TwilioSenderService: exception', [
                'message' => $e->getMessage(),
                'to'      => $to,
            ]);
            return false;
        }
    }
}
