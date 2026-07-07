<?php

namespace App\Http\Controllers\Api\WhatsApp;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Callback Twilio — statut de livraison des messages sortants.
 *
 * URL à configurer dans la console Twilio :
 *   https://<domaine>/api/whatsapp/status   (méthode POST)
 *
 * Twilio envoie les paramètres form-encoded :
 *   MessageSid    identifiant unique du message
 *   MessageStatus sent | delivered | read | failed | undelivered
 *   To            whatsapp:+241XXXXXXXXX  (destinataire)
 *   From          whatsapp:+<numéro_tondo>
 *   ErrorCode     présent seulement si failed/undelivered (ex: 30008)
 *   ErrorMessage  description de l'erreur
 */
class StatusController extends Controller
{
    /**
     * POST /api/whatsapp/status
     *
     * Reçoit les mises à jour de statut de livraison des messages sortants
     * envoyés par Tondo via Twilio WhatsApp. Twilio appelle cet endpoint
     * en POST pour chaque changement d'état d'un message.
     *
     * Paramètres Twilio (form-encoded) :
     *  - MessageSid    : identifiant unique du message Twilio
     *  - MessageStatus : sent | delivered | read | failed | undelivered
     *  - To            : destinataire (whatsapp:+241XXXXXXXXX)
     *  - From          : expéditeur (numéro WhatsApp Tondo)
     *  - ErrorCode     : code d'erreur Twilio (seulement si failed/undelivered)
     *  - ErrorMessage  : description de l'erreur
     *
     * Twilio attend impérativement un 2xx — on retourne 204 (aucun corps).
     * En cas de signature invalide, on retourne 200 vide pour éviter les retries.
     *
     * @return Response 204 No Content (ou 200 vide si signature invalide)
     */
    public function recevoir(Request $request): Response
    {
        // Validation de la signature — même algorithme que WebhookController.
        if (! $this->signatureValide($request)) {
            Log::warning('WhatsApp status callback : signature invalide', [
                'ip'  => $request->ip(),
                'sig' => $request->header('X-Twilio-Signature'),
            ]);
            // 200 vide pour éviter les retries Twilio sans révéler d'info.
            return response('', 200);
        }

        $messageSid = $request->input('MessageSid');
        $statut     = $request->input('MessageStatus');
        // Suppression du préfixe "whatsapp:" sur les deux numéros.
        $to         = str_replace('whatsapp:', '', $request->input('To', ''));
        $from       = str_replace('whatsapp:', '', $request->input('From', ''));
        $errorCode  = $request->input('ErrorCode');
        $errorMsg   = $request->input('ErrorMessage');

        Log::info('WhatsApp statut', [
            'message_id' => $messageSid,
            'statut'     => $statut,
            'to'         => $to,
            'error_code' => $errorCode,
        ]);

        // Persistance dans tondo_whatsapp_logs (upsert sur message_sid).
        // Le try/catch tolère une table absente (migration non encore jouée).
        try {
            DB::table(project_table('whatsapp_logs'))->updateOrInsert(
                // Clé de déduplication : un seul enregistrement par message.
                ['message_sid' => $messageSid],
                [
                    'statut'        => $statut,
                    'numero_dest'   => $to,
                    'numero_src'    => $from,
                    'error_code'    => $errorCode,
                    'error_message' => $errorMsg,
                    'updated_at'    => now(),
                    'created_at'    => now(),
                ],
            );
        } catch (\Throwable $e) {
            // Table pas encore créée en base — on log en debug, pas d'erreur critique.
            Log::debug(project_table('whatsapp_logs').' non disponible : ' . $e->getMessage());
        }

        // 204 : Twilio considère la livraison réussie, pas de retry.
        return response('', 204);
    }

    // ── Validation de signature Twilio (identique à WebhookController) ────────

    /**
     * Valide la signature X-Twilio-Signature.
     *
     * Bypass automatique en environnement `local` pour faciliter le dev.
     * Utilise `services.twilio.auth_token` (clé principale, pas wa_auth_token).
     */
    private function signatureValide(Request $request): bool
    {
        // Bypass automatique en local — pas besoin de configurer Twilio pour développer.
        if (app()->environment('local')) {
            return true;
        }

        // Clé principale Twilio (différente de la clé spécifique WhatsApp de WebhookController).
        $authToken = config('services.twilio.auth_token');
        $signature = $request->header('X-Twilio-Signature', '');

        if (! $authToken || ! $signature) {
            return false;
        }

        $url    = $request->url();
        $params = $request->post();
        // Tri alphabétique des clés — requis par la spec de signature Twilio.
        ksort($params);
        $data = $url . implode('', array_map(
            fn ($k, $v) => $k . $v,
            array_keys($params),
            array_values($params),
        ));

        // HMAC-SHA1 encodé en base64, comparaison en temps constant.
        $calcule = base64_encode(hash_hmac('sha1', $data, $authToken, true));

        return hash_equals($calcule, $signature);
    }
}
