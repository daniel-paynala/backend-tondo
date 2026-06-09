<?php

namespace App\Http\Controllers\Api\WhatsApp;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\BotService;
use App\Services\WhatsApp\SessionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Twilio WhatsApp — point d'entrée des messages entrants.
 *
 * URL à configurer dans la console Twilio :
 *   http://<domaine>/api/whatsapp/webhook   (méthode POST)
 *
 * Signature : désactivée si TWILIO_SKIP_SIGNATURE=true dans .env
 * (à n'utiliser qu'en dev/test, jamais en production).
 */
class WebhookController extends Controller
{
    public function __construct(
        private BotService     $bot,
        private SessionService $session,
    ) {}

    /**
     * POST /api/whatsapp/webhook
     */
    public function recevoir(Request $request): Response
    {
        if (! $this->signatureValide($request)) {
            Log::warning('WhatsApp webhook : signature Twilio invalide', [
                'ip'        => $request->ip(),
                'sig'       => $request->header('X-Twilio-Signature'),
                'url'       => $request->url(),
                'app_env'   => app()->environment(),
            ]);
            // En dev on laisse passer quand même pour ne pas bloquer les tests
            if (! app()->environment('production')) {
                Log::info('WhatsApp webhook : signature ignorée (non-production)');
            } else {
                return $this->twiml('');
            }
        }

        $from = str_replace('whatsapp:', '', $request->input('From', ''));
        $body = trim($request->input('Body', ''));

        Log::info('WhatsApp entrant', [
            'from'       => $from,
            'body'       => $body,
            'message_id' => $request->input('MessageSid'),
        ]);

        try {
            $reponse = $this->bot->traiter($from, $body);
        } catch (\Throwable $e) {
            Log::error('WhatsApp BotService exception', [
                'from'    => $from,
                'body'    => $body,
                'etape'   => $this->session->etape($from),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);

            // Reset la session pour ne pas bloquer l'utilisateur dans un état cassé
            $this->session->reset($from);

            $detail = app()->environment('production')
                ? ''
                : "\n\n🔧 _[dev] " . class_basename($e) . ' : ' . $e->getMessage()
                  . ' — ' . basename($e->getFile()) . ':' . $e->getLine() . '_';

            $reponse = "⚠️ Une erreur inattendue s'est produite. Votre session a été réinitialisée."
                . $detail
                . "\n\nTapez *1* pour Cotiser, *2* pour Rejoindre, *3* pour Créer, *4* pour Gérer, *5* pour Aide.";
        }

        if (is_array($reponse)) {
            [$texte, $pdfUrl] = $reponse;
            return $this->twimlAvecMedia($texte, $pdfUrl);
        }

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

    private function twimlAvecMedia(string $message, string $mediaUrl): Response
    {
        $safe = htmlspecialchars($message, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $xml  = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Response>
          <Message>
            {$safe}
            <Media>{$mediaUrl}</Media>
          </Message>
        </Response>
        XML;

        return response($xml, 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    }

    /**
     * Valide la signature X-Twilio-Signature.
     * Bypass si TWILIO_SKIP_SIGNATURE=true ou APP_ENV != production.
     */
    private function signatureValide(Request $request): bool
    {
        // Bypass explicite via .env
        if (config('services.twilio.skip_signature', false)) {
            return true;
        }

        // Bypass automatique hors production
        if (! app()->environment('production')) {
            return true;
        }

        $authToken = config('services.twilio.wa_auth_token');
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
