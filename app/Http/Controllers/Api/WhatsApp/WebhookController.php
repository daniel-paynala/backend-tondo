<?php

namespace App\Http\Controllers\Api\WhatsApp;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\BotService;
use App\Services\WhatsApp\Contracts\WhatsAppSender;
use App\Services\WhatsApp\SessionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook WhatsApp — point d'entrée des messages entrants.
 *
 * Deux fournisseurs cohabitent, pilotés par le driver global
 * `services.whatsapp.driver` :
 *   - 'twilio' (défaut) : POST form-encoded, signature X-Twilio-Signature,
 *                         réponse synchrone en TwiML XML.
 *   - 'meta'            : GET de vérification (hub.challenge), POST JSON Cloud
 *                         API, signature X-Hub-Signature-256, réponse 200 vide
 *                         puis envoi de la réponse du bot via la Graph API.
 *
 * URL à configurer :
 *   - Twilio : console Twilio > WhatsApp Senders > Webhook URL (POST)
 *   - Meta   : console Meta > WhatsApp > Configuration > URL de rappel (GET+POST)
 *
 * Le moteur conversationnel (BotService) est commun aux deux fournisseurs :
 * seul le transport (parsing + réponse) diffère selon le driver.
 */
class WebhookController extends Controller
{
    public function __construct(
        private BotService     $bot,
        private SessionService $session,
        // Sender résolu selon le driver (Twilio ou Meta). Sert à répondre aux
        // messages Meta, qui n'autorisent pas de réponse synchrone au webhook.
        private WhatsAppSender $sender,
    ) {}

    /**
     * GET /api/whatsapp/webhook — vérification du webhook Meta (hub.challenge).
     *
     * Meta appelle cet endpoint en GET lors de l'enregistrement de l'URL de
     * rappel, avec hub.mode=subscribe, hub.verify_token=<token configuré> et
     * hub.challenge=<valeur aléatoire>. On renvoie la valeur de hub.challenge
     * en texte brut si le token correspond, 403 sinon.
     *
     * Sans effet côté Twilio (Twilio n'appelle jamais l'URL en GET).
     */
    public function verifier(Request $request): Response
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge', '');

        $attendu = config('services.whatsapp.meta.verify_token');

        // Token correct et mode d'abonnement → on renvoie le challenge tel quel.
        if ($mode === 'subscribe' && $attendu && hash_equals((string) $attendu, (string) $token)) {
            return response((string) $challenge, 200, ['Content-Type' => 'text/plain']);
        }

        Log::warning('WhatsApp webhook (Meta) : vérification refusée', [
            'ip'   => $request->ip(),
            'mode' => $mode,
        ]);
        return response('Forbidden', 403, ['Content-Type' => 'text/plain']);
    }

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
        // Aiguillage selon le fournisseur actif. Le chemin Twilio ci-dessous
        // reste strictement inchangé ; Meta est traité à part (JSON + réponse
        // asynchrone via la Graph API).
        if (config('services.whatsapp.driver') === 'meta') {
            return $this->recevoirMeta($request);
        }

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

        // Envoi de la réponse via l'API REST Twilio plutôt qu'en TwiML.
        //
        // Le numéro WhatsApp est rattaché à un Messaging Service Twilio : dans
        // cette configuration, Twilio IGNORE les réponses TwiML <Message> du
        // webhook. La réponse doit donc partir par l'API REST (via $this->sender,
        // ici l'implémentation Twilio car driver = twilio). On renvoie ensuite un
        // TwiML vide (<Response/>) comme simple accusé 200.
        if (is_array($reponse)) {
            // Réponse tableau = [texte, urlPDF] — cas du reçu de paiement.
            [$texte, $pdfUrl] = $reponse;
            $this->sender->envoyerAvecPdf($from, $texte, $pdfUrl);
        } elseif ($reponse !== '') {
            $this->sender->envoyer($from, $reponse);
        }

        return $this->twiml('');
    }

    // ── Meta Cloud API ──────────────────────────────────────────────────────

    /**
     * Traite un webhook Meta WhatsApp Cloud API (POST JSON).
     *
     * Différences majeures avec Twilio :
     *  - Corps JSON structuré (entry[].changes[].value.messages[] / statuses[]).
     *  - Signature HMAC-SHA256 dans l'en-tête X-Hub-Signature-256 (app secret).
     *  - Pas de réponse synchrone : on renvoie 200 vide, et la réponse du bot
     *    part par un appel sortant à la Graph API (via $this->sender).
     *
     * On répond toujours 200 pour éviter que Meta ne retente le webhook.
     */
    private function recevoirMeta(Request $request): Response
    {
        // 1. Validation de la signature (bypass hors production, comme Twilio).
        if (! $this->signatureMetaValide($request)) {
            Log::warning('WhatsApp webhook (Meta) : signature invalide', [
                'ip'  => $request->ip(),
                'sig' => $request->header('X-Hub-Signature-256'),
            ]);
            if (app()->environment('production')) {
                // 200 quand même : ne pas déclencher les retries Meta.
                return response('', 200);
            }
        }

        $data = $request->json()->all();

        // 2. Parcours du payload : chaque entry peut contenir plusieurs changes.
        foreach ($data['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                // 2a. Accusés de statut (sent/delivered/read/failed) → journalisés.
                foreach ($value['statuses'] ?? [] as $statut) {
                    $this->journaliserStatutMeta($statut);
                }

                // 2b. Messages entrants → moteur du bot puis réponse sortante.
                foreach ($value['messages'] ?? [] as $message) {
                    $this->traiterMessageMeta($message);
                }
            }
        }

        // Meta attend un 2xx rapide.
        return response('', 200);
    }

    /**
     * Traite un message Meta entrant : extrait l'expéditeur + le texte, appelle
     * le bot, puis envoie la réponse via la Graph API (texte ou texte + PDF).
     *
     * @param array<string,mixed> $message Un élément de value.messages[].
     */
    private function traiterMessageMeta(array $message): void
    {
        // Meta fournit le numéro en chiffres (ex : "24177..."). On le normalise
        // en +E.164 pour rester cohérent avec le format Twilio et les clés de
        // session (SessionService retire le "+" de son côté).
        $from = '+' . ltrim((string) ($message['from'] ?? ''), '+');

        // Seuls les messages texte sont pris en charge par le FSM. Les autres
        // types (image, audio…) sont traités comme du texte vide → menu d'aide.
        // Corps du message selon le type :
        //  - 'text'        → le texte tapé.
        //  - 'interactive' → réponse à un bouton/liste : on récupère l'`id` du
        //                    choix (ex "1"), réinjecté tel quel dans le bot texte,
        //                    donc le FSM existant le traite sans aucune modification.
        //  - autre (image, audio…) → chaîne vide → menu d'aide.
        $body = match ($message['type'] ?? '') {
            'text'        => trim((string) ($message['text']['body'] ?? '')),
            'interactive' => trim((string) (
                $message['interactive']['button_reply']['id']
                ?? $message['interactive']['list_reply']['id']
                ?? ''
            )),
            default       => '',
        };

        Log::info('WhatsApp entrant (Meta)', [
            'from'       => $from,
            'body'       => $body,
            'message_id' => $message['id'] ?? null,
            'type'       => $message['type'] ?? null,
        ]);

        // Mode moderne (Meta) : dès la réception, on accuse lecture (coches bleues)
        // et on affiche « en train d'écrire… » pendant que le bot réfléchit — il
        // paraît ainsi vivant. Best-effort, gaté comme les menus tappables :
        // en mode 'texte' (défaut) rien ne change.
        $senderMeta = $this->sender;
        if (config('services.whatsapp.ui') === 'moderne'
            && $senderMeta instanceof \App\Services\WhatsApp\MetaSenderService
            && ! empty($message['id'])) {
            $senderMeta->marquerLuEtEcrit((string) $message['id']);
        }

        try {
            $reponse = $this->bot->traiter($from, $body);
        } catch (\Throwable $e) {
            Log::error('WhatsApp BotService exception (Meta)', [
                'from'    => $from,
                'body'    => $body,
                'etape'   => $this->session->etape($from),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            // Reset de session pour ne pas bloquer l'utilisateur dans un état cassé.
            $this->session->reset($from);
            $reponse = "⚠️ Une erreur inattendue s'est produite. Votre session a été réinitialisée."
                . "\n\nTapez *1* pour Cotiser, *2* pour Rejoindre, *3* pour Créer, *4* pour Gérer, *5* pour Aide.";
        }

        // Réponse tableau = [texte, urlPDF] → document joint ; sinon texte (ou interactif).
        if (is_array($reponse)) {
            [$texte, $pdfUrl] = $reponse;
            $this->sender->envoyerAvecPdf($from, $texte, $pdfUrl);
        } elseif ($reponse !== '') {
            $this->envoyerReponse($from, $reponse);
        }
    }

    /**
     * Envoie la réponse texte du bot — en version INTERACTIVE si le mode
     * « moderne » est actif ET que l'étape courante a une version tappable.
     *
     * Filet de sécurité à plusieurs niveaux, dans cet ordre :
     *   1. services.whatsapp.ui ≠ 'moderne'      → texte (comportement historique).
     *   2. Fournisseur ≠ Meta (ex : Twilio)      → texte (interactif non géré ici).
     *   3. Étape sans version interactive         → texte (BotUiMenus::pour null).
     *   4. Échec de l'envoi interactif            → texte (repli).
     * Le bot texte (BotService) n'est jamais touché : on ne fait que CHOISIR le
     * rendu de sa réponse.
     *
     * @param string $from   Destinataire E.164.
     * @param string $texte  Réponse texte du bot (sert aussi de repli).
     */
    private function envoyerReponse(string $from, string $texte): void
    {
        // 1) Mode texte (défaut) : aucun changement.
        if (config('services.whatsapp.ui') !== 'moderne') {
            $this->sender->envoyer($from, $texte);
            return;
        }

        // 2) Les menus interactifs sont une capacité SPÉCIFIQUE à Meta. Sur tout
        //    autre fournisseur (Twilio…), on garde le texte inchangé.
        $sender = $this->sender;
        if (! $sender instanceof \App\Services\WhatsApp\MetaSenderService) {
            $this->sender->envoyer($from, $texte);
            return;
        }

        // 3) L'étape courante (après traiter) a-t-elle une version interactive ?
        $etape = $this->session->etape($from);
        $spec  = \App\Services\WhatsApp\BotUiMenus::pour($etape);
        if ($spec === null) {
            $sender->envoyer($from, $texte);
            return;
        }

        // 4) Corps du message interactif = texte du bot NETTOYÉ de son bloc
        //    d'options numérotées (préserve intro/récap/avertissements dynamiques),
        //    ou un corps sur-mesure si le spec en fournit un ('texte'). Si le
        //    nettoyage vide tout, on retombe sur le texte brut.
        $corps = $spec['texte'] ?? \App\Services\WhatsApp\BotUiMenus::corpsSansOptions($texte);
        if ($corps === '') {
            $corps = $texte;
        }

        // Tentative d'envoi interactif ; tout échec → repli sur le texte.
        $envoye = false;
        try {
            if ($spec['type'] === 'liste') {
                $envoye = $sender->envoyerListe($from, $corps, $spec['bouton'], $spec['sections']);
            } else {
                // Boutons : WhatsApp plafonne à 3/message → on découpe en groupes
                // de 3 et on envoie PLUSIEURS messages à la suite (ex. menu à 5 =
                // un message de 3 puis un de 2). Le corps des messages suivants
                // vient de 'suite'. Le succès se juge sur le 1er groupe (l'essentiel) :
                // un échec sur un groupe suivant est logué par le sender mais ne
                // redéclenche pas le repli texte (les premiers boutons sont déjà partis).
                foreach (array_chunk($spec['boutons'], 3) as $i => $groupe) {
                    $corpsGroupe = $i === 0 ? $corps : ($spec['suite'] ?? '⤵️ Ou :');
                    $ok = $sender->envoyerBoutons($from, $corpsGroupe, $groupe);
                    if ($i === 0) {
                        $envoye = $ok;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsApp UI moderne : échec du rendu interactif, repli texte', [
                'etape' => $etape,
                'error' => $e->getMessage(),
            ]);
        }

        if (! $envoye) {
            $sender->envoyer($from, $texte);
        }
    }

    /**
     * Enregistre un accusé de statut Meta dans tondo_whatsapp_logs.
     *
     * Table partagée avec les statuts Twilio (StatusController) : on mappe les
     * champs Meta (id, status, recipient_id) sur le même schéma. Tolérant à
     * l'absence de table (upsert dans un try/catch).
     *
     * @param array<string,mixed> $statut Un élément de value.statuses[].
     */
    private function journaliserStatutMeta(array $statut): void
    {
        try {
            DB::table(project_table('whatsapp_logs'))->updateOrInsert(
                ['message_sid' => $statut['id'] ?? ''],
                [
                    'statut'        => $statut['status']       ?? null,
                    'numero_dest'   => $statut['recipient_id'] ?? null,
                    'error_code'    => $statut['errors'][0]['code']    ?? null,
                    'error_message' => $statut['errors'][0]['title']   ?? null,
                    'updated_at'    => now(),
                    'created_at'    => now(),
                ],
            );
        } catch (\Throwable $e) {
            Log::debug(project_table('whatsapp_logs') . ' (Meta) non disponible : ' . $e->getMessage());
        }
    }

    /**
     * Valide la signature X-Hub-Signature-256 d'un webhook Meta.
     *
     * Meta signe le corps BRUT de la requête en HMAC-SHA256 avec l'App Secret,
     * et transmet le résultat préfixé "sha256=" dans l'en-tête. On recalcule et
     * on compare en temps constant.
     *
     * Bypass si services.whatsapp.meta.skip_signature (dev/CI) ou hors production.
     */
    private function signatureMetaValide(Request $request): bool
    {
        // Bypass explicite (local/CI) ou automatique hors production.
        if (config('services.whatsapp.meta.skip_signature', false)) {
            return true;
        }
        if (! app()->environment('production')) {
            return true;
        }

        $appSecret = config('services.whatsapp.meta.app_secret');
        $signature = $request->header('X-Hub-Signature-256', '');

        if (! $appSecret || ! $signature) {
            return false;
        }

        // Signature attendue = "sha256=" + HMAC-SHA256(corps brut, app secret).
        $attendu = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($attendu, $signature);
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
