<?php

namespace App\Http\Controllers\Api\WhatsApp;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Twilio WhatsApp — point d'entrée des messages entrants.
 *
 * URL à configurer dans la console Twilio :
 *   https://<domaine>/api/whatsapp/webhook   (méthode POST)
 *
 * Twilio envoie des paramètres form-encoded :
 *   From        whatsapp:+241XXXXXXXXX
 *   To          whatsapp:+<numéro_tondo>
 *   Body        texte du message
 *   MessageSid  identifiant unique du message
 *   NumMedia    nombre de médias joints (0 en général)
 *
 * La méthode recevoir() valide la signature X-Twilio-Signature,
 * parse le message, et renvoie du TwiML (XML).
 */
class WebhookController extends Controller
{
    // ── Commandes reconnues ───────────────────────────────────────────────────

    /** Retourne les commandes disponibles. */
    private const CMD_AIDE      = ['aide', 'help', 'bonjour', 'salut', 'hello', 'hi', 'menu'];
    /** Préfixe pour consulter une cagnotte : "CAGNOTTE 12345" */
    private const CMD_CAGNOTTE  = 'cagnotte';
    /** Préfixe pour cotiser : "COTISER 12345" */
    private const CMD_COTISER   = 'cotiser';

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /api/whatsapp/webhook
     *
     * Point d'entrée Twilio. Retourne du TwiML (Content-Type: text/xml).
     */
    public function recevoir(Request $request): Response
    {
        // 1 — Vérifier la signature Twilio (protège contre les faux appels)
        if (! $this->signatureValide($request)) {
            Log::warning('WhatsApp webhook : signature Twilio invalide', [
                'ip'  => $request->ip(),
                'sig' => $request->header('X-Twilio-Signature'),
            ]);
            // On renvoie 403 mais en TwiML vide pour ne pas déclencher les
            // retries Twilio sur une erreur 5xx.
            return $this->twiml('');
        }

        // 2 — Parser le message entrant
        $from       = $request->input('From', '');          // whatsapp:+241XXXXXXXXX
        $body       = trim($request->input('Body', ''));
        $messageSid = $request->input('MessageSid', '');

        // Extraire le numéro brut (+241XXXXXXXXX)
        $numero = str_replace('whatsapp:', '', $from);

        Log::info('WhatsApp entrant', [
            'from'       => $numero,
            'body'       => $body,
            'message_id' => $messageSid,
        ]);

        // 3 — Router vers le bon handler
        $reponse = $this->router($numero, $body);

        return $this->twiml($reponse);
    }

    // ── Routing des commandes ─────────────────────────────────────────────────

    private function router(string $numero, string $body): string
    {
        $texte = mb_strtolower(trim($body));
        $mots  = preg_split('/\s+/', $texte, 2);
        $cmd   = $mots[0] ?? '';
        $args  = trim($mots[1] ?? '');

        // Aide / menu
        if (in_array($cmd, self::CMD_AIDE, true) || $texte === '') {
            return $this->reponseAide();
        }

        // Consulter une cagnotte : "cagnotte 12345"
        if ($cmd === self::CMD_CAGNOTTE && $args !== '') {
            return $this->reponseCagnotte($args);
        }

        // Cotiser : "cotiser 12345" (initie le flux, répond avec un lien web)
        if ($cmd === self::CMD_COTISER && $args !== '') {
            return $this->reponseCotiser($numero, $args);
        }

        // Commande non reconnue
        return $this->reponseInconnue($texte);
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    private function reponseAide(): string
    {
        return <<<TXT
        👋 Bienvenue sur *Tondo* !

        Voici ce que vous pouvez faire :

        📋 *CAGNOTTE [référence]*
        Consulter le détail d'une cagnotte.
        _Exemple :_ CAGNOTTE 12345

        💰 *COTISER [référence]*
        Recevoir le lien de paiement pour une cagnotte.
        _Exemple :_ COTISER 12345

        ❓ *AIDE*
        Afficher ce menu.

        Pour tout autre service, téléchargez l'app Tondo ou connectez-vous sur tondo.ga
        TXT;
    }

    private function reponseCagnotte(string $reference): string
    {
        $reference = preg_replace('/\D/', '', $reference);  // garder seulement les chiffres

        $cagnotte = TondoCagnotte::where('reference', $reference)->first();

        if (! $cagnotte) {
            return "❌ Aucune cagnotte trouvée avec la référence *{$reference}*.\nVérifiez la référence et réessayez.";
        }

        $type    = $cagnotte->type === 'tontine_periodique' ? 'Tontine' : 'Cotisation';
        $statut  = match ($cagnotte->statut) {
            'active'   => '🟢 Active',
            'en_cours' => '🔵 En cours',
            'cloturee' => '🔴 Clôturée',
            default    => $cagnotte->statut,
        };
        $collecte = number_format($cagnotte->montant_collecte ?? 0, 0, ',', ' ');

        return <<<TXT
        📊 *{$cagnotte->titre}*
        Type : {$type} · Réf : #{$reference}
        Statut : {$statut}
        Montant collecté : *{$collecte} FCFA*
        Participants : {$cagnotte->nombre_inscrits}/{$cagnotte->nombre_participants}

        Pour cotiser, tapez : COTISER {$reference}
        TXT;
    }

    private function reponseCotiser(string $numero, string $reference): string
    {
        $reference = preg_replace('/\D/', '', $reference);
        $appUrl    = config('app.url', 'http://51.44.254.213');

        $cagnotte = TondoCagnotte::where('reference', $reference)->first();

        if (! $cagnotte) {
            return "❌ Référence *{$reference}* introuvable. Vérifiez et réessayez.";
        }

        if ($cagnotte->statut === 'cloturee') {
            return "❌ La cagnotte *{$cagnotte->titre}* (#{$reference}) est clôturée. Les paiements ne sont plus acceptés.";
        }

        $lien = "{$appUrl}/cagnottes/{$reference}";

        return <<<TXT
        💳 *{$cagnotte->titre}* — #{$reference}

        Cliquez sur ce lien pour payer :
        👉 {$lien}

        Le paiement se fait via Mobile Money (Airtel/Moov).
        Les frais sont à la charge du cotisant.
        TXT;
    }

    private function reponseInconnue(string $texte): string
    {
        $extrait = mb_substr($texte, 0, 30);
        return <<<TXT
        🤔 Je n'ai pas compris « {$extrait}… »

        Tapez *AIDE* pour voir les commandes disponibles.
        TXT;
    }

    // ── Helpers TwiML & signature ─────────────────────────────────────────────

    /**
     * Enveloppe le texte dans une réponse TwiML <Message>.
     * Twilio exige Content-Type: text/xml.
     */
    private function twiml(string $message): Response
    {
        $safe = htmlspecialchars($message, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $xml  = $message === ''
            ? '<?xml version="1.0" encoding="UTF-8"?><Response/>'
            : "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>{$safe}</Message></Response>";

        return response($xml, 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    }

    /**
     * Valide la signature X-Twilio-Signature.
     *
     * Algorithme officiel Twilio :
     *  1. Construire la chaîne = URL complète + params POST triés par clé.
     *  2. Signer avec HMAC-SHA1 (auth_token).
     *  3. Comparer (hash_equals) avec le header.
     *
     * En dev (APP_ENV=local) la vérification est bypassée pour faciliter
     * les tests avec ngrok ou curl.
     */
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

        // URL complète telle que Twilio l'a utilisée (schéma + host + path)
        $url = $request->url();

        // Paramètres POST triés alphabétiquement
        $params = $request->post();
        ksort($params);
        $data = $url . implode('', array_map(
            fn ($k, $v) => $k . $v,
            array_keys($params),
            array_values($params),
        ));

        $calcule = base64_encode(hash_hmac('sha1', $data, $authToken, true));

        return hash_equals($calcule, $signature);
    }
}
