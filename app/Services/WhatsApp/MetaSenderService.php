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

    /**
     * Envoie un menu à BOUTONS de réponse tappables (interactive type "button").
     *
     * WhatsApp accepte 3 boutons au maximum ; les titres sont tronqués à 20 car.
     * L'`id` de chaque bouton est renvoyé tel quel par Meta quand l'utilisateur
     * tape → il doit correspondre au choix attendu par le bot texte (ex : "1").
     * N'existe PAS sur l'interface WhatsAppSender : méthode spécifique à Meta,
     * appelée uniquement après un test instanceof côté WebhookController.
     *
     * @param  string $to       Numéro E.164 du destinataire.
     * @param  string $texte    Corps du message (la question).
     * @param  array<int,array{id:string,titre:string}> $boutons  Options (≤3).
     * @return bool             true si envoyé, false sinon (→ repli texte par l'appelant).
     */
    public function envoyerBoutons(string $to, string $texte, array $boutons): bool
    {
        // Construction des boutons (max 3, imposé par WhatsApp).
        $items = [];
        foreach (array_slice($boutons, 0, 3) as $b) {
            $items[] = [
                'type'  => 'reply',
                'reply' => [
                    'id'    => (string) $b['id'],
                    'title' => mb_substr((string) $b['titre'], 0, 20),
                ],
            ];
        }

        return $this->call($to, [
            'type'        => 'interactive',
            'interactive' => [
                'type'   => 'button',
                'body'   => ['text' => mb_substr($texte, 0, 1024)],
                'action' => ['buttons' => $items],
            ],
        ]);
    }

    /**
     * Envoie un menu LISTE (interactive type "list") : un bouton qui déroule
     * jusqu'à 10 lignes réparties en sections.
     *
     * Limites WhatsApp appliquées défensivement : libellé du bouton ≤20 car.,
     * titre de section ≤24, titre de ligne ≤24, description ≤72. Les `id` de
     * ligne suivent la même règle que les boutons (= choix du bot texte).
     *
     * @param  string $to        Numéro E.164 du destinataire.
     * @param  string $texte     Corps du message (la question).
     * @param  string $bouton    Libellé du bouton d'ouverture (ex : "Menu").
     * @param  array<int,array{titre:string,lignes:array<int,array{id:string,titre:string,desc?:string}>}> $sections
     * @return bool              true si envoyé, false sinon (→ repli texte par l'appelant).
     */
    public function envoyerListe(string $to, string $texte, string $bouton, array $sections): bool
    {
        // Transformation des sections « métier » vers le format attendu par Meta.
        $secs = [];
        foreach ($sections as $s) {
            $rows = [];
            foreach ($s['lignes'] as $r) {
                $row = [
                    'id'    => (string) $r['id'],
                    'title' => mb_substr((string) $r['titre'], 0, 24),
                ];
                // La description est optionnelle côté Meta.
                if (! empty($r['desc'])) {
                    $row['description'] = mb_substr((string) $r['desc'], 0, 72);
                }
                $rows[] = $row;
            }
            $secs[] = [
                'title' => mb_substr((string) $s['titre'], 0, 24),
                'rows'  => $rows,
            ];
        }

        return $this->call($to, [
            'type'        => 'interactive',
            'interactive' => [
                'type'   => 'list',
                'body'   => ['text' => mb_substr($texte, 0, 1024)],
                'action' => [
                    'button'   => mb_substr($bouton, 0, 20),
                    'sections' => $secs,
                ],
            ],
        ]);
    }

    /**
     * Accuse réception d'un message entrant (coches bleues) ET affiche
     * l'indicateur « en train d'écrire… » côté utilisateur.
     *
     * La Cloud API combine les deux dans un seul appel « mark as read » enrichi
     * d'un typing_indicator. L'indicateur reste actif ~25 s ou jusqu'au prochain
     * message du bot (donc dissipé dès que la réponse part). Best-effort : toute
     * erreur est loggée sans être propagée (n'interrompt jamais le bot).
     *
     * NB : payload différent des envois (pas de 'to' ni 'recipient_type', mais
     * un 'status'='read' + 'message_id'), d'où un appel direct plutôt que call().
     *
     * @param  string $messageId  Identifiant du message entrant (wamid…).
     * @return bool               true si l'API répond 2xx, false sinon.
     */
    public function marquerLuEtEcrit(string $messageId): bool
    {
        $url = "https://graph.facebook.com/{$this->graphVersion}/{$this->phoneNumberId}/messages";

        try {
            $response = Http::withToken($this->token)
                ->asJson()
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'status'            => 'read',
                    'message_id'        => $messageId,
                    'typing_indicator'  => ['type' => 'text'],
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::warning('MetaSenderService: accusé lu/écrit non-2xx', [
                'status'     => $response->status(),
                'body'       => $response->body(),
                'message_id' => $messageId,
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::error('MetaSenderService: exception accusé lu/écrit', [
                'message'    => $e->getMessage(),
                'message_id' => $messageId,
            ]);
            return false;
        }
    }

    /**
     * Envoie un message « Flow » : un bouton qui ouvre un formulaire natif WhatsApp.
     *
     * À la soumission du formulaire, Meta renvoie au webhook un message
     * interactif de type `nfm_reply` contenant les réponses (JSON). Le
     * `flow_token` permet de corréler la soumission au parcours (ex. quel Flow).
     *
     * @param  string               $to         Numéro E.164 du destinataire.
     * @param  string               $texte      Corps du message (au-dessus du bouton).
     * @param  string               $flowId     ID du Flow PUBLIÉ chez Meta.
     * @param  string               $cta        Libellé du bouton d'ouverture (≤30 car.).
     * @param  string               $flowToken  Jeton de corrélation (renvoyé dans le nfm_reply).
     * @param  string               $screen     ID de l'écran d'entrée du Flow (ex : 'CREER_CAGNOTTE').
     * @param  array<string,mixed>  $data       Données initiales injectées dans l'écran (pré-remplissage).
     * @return bool                             true si envoyé, false sinon (→ repli texte par l'appelant).
     */
    public function envoyerFlow(
        string $to,
        string $texte,
        string $flowId,
        string $cta,
        string $flowToken,
        string $screen,
        array $data = [],
    ): bool {
        // Charge utile du bouton Flow (Cloud API « flow_message_version » = "3").
        $parametres = [
            'flow_message_version' => '3',
            'flow_token'           => $flowToken,
            'flow_id'              => $flowId,
            'flow_cta'             => mb_substr($cta, 0, 30),
            'flow_action'          => 'navigate',
            'flow_action_payload'  => ['screen' => $screen],
        ];

        // Pré-remplissage éventuel de l'écran d'entrée (ex. numéro par défaut).
        if (! empty($data)) {
            $parametres['flow_action_payload']['data'] = $data;
        }

        return $this->call($to, [
            'type'        => 'interactive',
            'interactive' => [
                'type'   => 'flow',
                'body'   => ['text' => mb_substr($texte, 0, 1024)],
                'action' => ['name' => 'flow', 'parameters' => $parametres],
            ],
        ]);
    }

    /**
     * Envoie un message avec un BOUTON LIEN (interactive type "cta_url") : un
     * bouton qui ouvre une URL dans le navigateur (reçu, cagnotte, app…).
     *
     * @param  string $to      Numéro E.164 du destinataire.
     * @param  string $texte   Corps du message (au-dessus du bouton).
     * @param  string $label   Libellé du bouton (≤20 car.).
     * @param  string $url      URL https ouverte au clic.
     * @return bool             true si envoyé, false sinon (→ repli texte par l'appelant).
     */
    public function envoyerCta(string $to, string $texte, string $label, string $url): bool
    {
        return $this->call($to, [
            'type'        => 'interactive',
            'interactive' => [
                'type'   => 'cta_url',
                'body'   => ['text' => mb_substr($texte, 0, 1024)],
                'action' => [
                    'name'       => 'cta_url',
                    'parameters' => [
                        'display_text' => mb_substr($label, 0, 20),
                        'url'          => $url,
                    ],
                ],
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
