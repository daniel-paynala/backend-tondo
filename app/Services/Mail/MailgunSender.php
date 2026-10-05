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

        // Même préfixe que le bandeau du gabarit : la liste des messages suffit à
        // distinguer un envoi de recette d'un envoi de production.
        if (! app()->environment('production')) {
            $subject = '[TEST] '.$subject;
        }

        // Détournement hors production, miroir de celui des SMS.
        //
        // Ce service appelle l'API Mailgun en direct : il ignore MAIL_MAILER,
        // et un `MAIL_MAILER=log` ne l'arrête donc PAS. Or la base de recette
        // contient des copies de fiches réelles — sans ce garde-fou, un essai
        // écrit à de vrais commerçants. Les SMS ont ce détournement depuis le
        // début ; l'e-mail ne l'avait pas, l'oubli est corrigé ici.
        //
        // Le destinataire d'origine est rappelé dans le sujet : sans lui, on
        // ne saurait pas, en relisant sa boîte, à qui le message était destiné.
        $forcee = trim((string) config('services.mailgun.destinataire_force'));
        if (! app()->environment('production') && $forcee !== '') {
            $subject = $subject.' → '.$to;
            $to      = $forcee;
        }

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
