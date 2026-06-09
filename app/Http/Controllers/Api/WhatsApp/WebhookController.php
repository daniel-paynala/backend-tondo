<?php

namespace App\Http\Controllers\Api\WhatsApp;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\BotService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Twilio WhatsApp — point d'entrée des messages entrants.
 *
 * URL à configurer dans la console Twilio :
 *   https://<domaine>/api/whatsapp/webhook   (méthode POST)
 *
 * Ce controller valide la signature, extrait le message,
 * délègue au BotService et renvoie la réponse en TwiML.
 */
class WebhookController extends Controller
{
    public function __construct(private BotService $bot) {}

    /**
     * POST /api/whatsapp/webhook
     */
    public function recevoir(Request $request): Response
    {
        if (! $this->signatureValide($request)) {
            Log::warning('WhatsApp webhook : signature Twilio invalide', [
                'ip'  => $request->ip(),
                'sig' => $request->header('X-Twilio-Signature'),
            ]);
            return $this->twiml('');
        }

        $from = str_replace('whatsapp:', '', $request->input('From', ''));
        $body = trim($request->input('Body', ''));

        Log::info('WhatsApp entrant', [
            'from'       => $from,
            'body'       => $body,
            'message_id' => $request->input('MessageSid'),
        ]);

        $reponse = $this->bot->traiter($from, $body);

        return $this->twiml($reponse);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function twiml(string $message): Response
    {
        $safe = htmlspecialchars($message, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $xml  = $message === ''
            ? '<?xml version="1.0" encoding="UTF-8"?><Response/>'
            : "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>{$safe}</Message></Response>";

        return response($xml, 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    }

    private function signatureValide(Request $request): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        $authToken = config('services.twilio.auth_token');
        $signature = $request->header('X-Twilio-Signature', '');

        if (! $authToken || ! $signature) {
            return false;
        }

        $url    = $request->url();
        $params = $request->post();
        ksort($params);
        $data = $url . implode('', array_map(
            fn ($k, $v) => $k . $v,
            array_keys($params),
            array_values($params),
        ));

        return hash_equals(
            base64_encode(hash_hmac('sha1', $data, $authToken, true)),
            $signature,
        );
    }
}
