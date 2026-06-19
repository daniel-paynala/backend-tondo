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
     *
     * Point d'entrée de tous les messages WhatsApp entrants envoyés par les
     * utilisateurs. Twilio appelle cet endpoint en POST (form-encoded) dès
     * qu'un message arrive sur le numéro WhatsApp Tondo.
     *
     * Flux :
     *  1. Validation de la signature X-Twilio-Signature (HMAC-SHA1).
     *  2. Extraction de l'expéditeur (From) et du texte (Body).
     *  3. Délégation au BotService pour traiter le message et générer la réponse.
     *  4. Renvoi de la réponse en TwiML XML (texte seul ou texte + média PDF).
     *
     * En cas d'exception non gérée : reset de session + message d'erreur convivial.
     * Twilio attend un 2xx — on retourne toujours 200 même en cas d'erreur.
     *
     * @return Response TwiML XML (Content-Type: text/xml)
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
            // En dev on laisse passer quand même pour ne pas bloquer les tests locaux.
            if (! app()->environment('production')) {
                Log::info('WhatsApp webhook : signature ignorée (non-production)');
            } else {
                // En production : réponse vide mais 200 pour éviter les retries Twilio.
                return $this->twiml('');
            }
        }

        // Suppression du préfixe "whatsapp:" que Twilio ajoute au numéro.
        $from = str_replace('whatsapp:', '', $request->input('From', ''));
        $body = trim($request->input('Body', ''));

        Log::info('WhatsApp entrant', [
            'from'       => $from,
            'body'       => $body,
            'message_id' => $request->input('MessageSid'),
        ]);

        try {
            // Le BotService gère le FSM (machine d'état) de la conversation.
            $reponse = $this->bot->traiter($from, $body);
        } catch (\Throwable $e) {
            Log::error('WhatsApp BotService exception', [
                'from'    => $from,
                'body'    => $body,
                // Étape de session courante pour diagnostiquer où le FSM a bloqué.
                'etape'   => $this->session->etape($from),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);

            // Reset la session pour ne pas bloquer l'utilisateur dans un état cassé.
            $this->session->reset($from);

            // En dev : détails techniques dans le message WhatsApp pour débugguer.
            // En production : message générique uniquement.
            $detail = app()->environment('production')
                ? ''
                : "\n\n🔧 _[dev] " . class_basename($e) . ' : ' . $e->getMessage()
                  . ' — ' . basename($e->getFile()) . ':' . $e->getLine() . '_';

            $reponse = "⚠️ Une erreur inattendue s'est produite. Votre session a été réinitialisée."
                . $detail
                . "\n\nTapez *1* pour Cotiser, *2* pour Rejoindre, *3* pour Créer, *4* pour Gérer, *5* pour Aide.";
        }

        // Réponse tableau = [texte, urlPDF] — cas du reçu de paiement.
        if (is_array($reponse)) {
            [$texte, $pdfUrl] = $reponse;
            return $this->twimlAvecMedia($texte, $pdfUrl);
        }

        return $this->twiml($reponse);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Construit une réponse TwiML texte simple.
     * Message vide → `<Response/>` (réponse silencieuse, évite les retries).
     */
    private function twiml(string $message): Response
    {
        // Échappement XML obligatoire pour les caractères spéciaux (apostrophes, <, >...).
        $safe = htmlspecialchars($message, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $xml  = $message === ''
            ? '<?xml version="1.0" encoding="UTF-8"?><Response/>'
            : "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>{$safe}</Message></Response>";

        return response($xml, 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    }

    /**
     * Construit une réponse TwiML texte + média (PDF reçu de paiement).
     * Twilio récupère le fichier à l'URL donnée et l'envoie en pièce jointe.
     */
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
     * Valide la signature X-Twilio-Signature selon l'algorithme officiel Twilio.
     *
     * Algorithme :
     *  1. Concatène l'URL complète avec les paramètres POST triés par clé.
     *  2. Calcule le HMAC-SHA1 avec le auth token WhatsApp comme secret.
     *  3. Compare (timing-safe) avec la signature fournie en base64.
     *
     * Bypass si TWILIO_SKIP_SIGNATURE=true (dev) ou APP_ENV != production.
     * Ne jamais désactiver en production — risque d'injection de faux messages.
     */
    private function signatureValide(Request $request): bool
    {
        // Bypass explicite via .env (à n'utiliser qu'en local ou CI).
        if (config('services.twilio.skip_signature', false)) {
            return true;
        }

        // Bypass automatique hors production (facilite le dev sans compte Twilio live).
        if (! app()->environment('production')) {
            return true;
        }

        // Clé spécifique au canal WhatsApp (différente de la clé SMS Verify).
        $authToken = config('services.twilio.wa_auth_token');
        $signature = $request->header('X-Twilio-Signature', '');

        if (! $authToken || ! $signature) {
            return false;
        }

        $url    = $request->url();
        $params = $request->post();
        // Tri alphabétique des clés — requis par la spec Twilio.
        ksort($params);
        $data = $url . implode('', array_map(
            fn ($k, $v) => $k . $v,
            array_keys($params),
            array_values($params),
        ));

        // Comparaison en temps constant pour résister aux timing attacks.
        return hash_equals(
            base64_encode(hash_hmac('sha1', $data, $authToken, true)),
            $signature,
        );
    }
}
