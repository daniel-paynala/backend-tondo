<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envoi d'e-mails transactionnels via l'API HTTP Mailgun (compte Paynala,
 * domaine paynala.com). On appelle l'API directement — pas besoin du transport
 * Mailgun de Laravel ni d'un package composer supplémentaire.
 */
class MailgunSender
{
    /**
     * Envoie un e-mail HTML. Renvoie true si Mailgun a accepté le message.
     * En cas de config manquante ou d'erreur : log + false (non bloquant).
     */
    public function envoyer(string $to, string $subject, string $html): bool
    {
        $secret   = config('services.mailgun.secret');
        $domain   = config('services.mailgun.domain');
        $endpoint = config('services.mailgun.endpoint', 'api.eu.mailgun.net');
        $from     = config('services.mailgun.from_name').' <'.config('services.mailgun.from').'>';

        if (! $secret || ! $domain) {
            Log::warning('MailgunSender : MAILGUN_SECRET/MAILGUN_DOMAIN manquant — e-mail non envoyé', ['to' => $to]);

            return false;
        }

        try {
            $res = Http::asForm()
                ->withBasicAuth('api', $secret)
                ->post("https://{$endpoint}/v3/{$domain}/messages", [
                    'from'    => $from,
                    'to'      => $to,
                    'subject' => $subject,
                    'html'    => $html,
                ]);

            if (! $res->successful()) {
                Log::error('MailgunSender : réponse non-2xx', [
                    'to'     => $to,
                    'status' => $res->status(),
                    'body'   => $res->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('MailgunSender : exception', ['to' => $to, 'message' => $e->getMessage()]);

            return false;
        }
    }
}
