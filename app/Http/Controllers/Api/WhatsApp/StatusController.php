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
     * Reçoit les mises à jour de statut Twilio et les persiste dans
     * tondo_whatsapp_logs. Twilio attend un 2xx — on renvoie 204 (pas de corps).
     */
    public function recevoir(Request $request): Response
    {
        // Vérification signature (même algo que WebhookController)
        if (! $this->signatureValide($request)) {
            Log::warning('WhatsApp status callback : signature invalide', [
                'ip'  => $request->ip(),
                'sig' => $request->header('X-Twilio-Signature'),
            ]);
            // 200 vide pour éviter les retries Twilio
            return response('', 200);
        }

        $messageSid = $request->input('MessageSid');
        $statut     = $request->input('MessageStatus');
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

        // Persister dans tondo_whatsapp_logs si la table existe
        try {
            DB::table('tondo_whatsapp_logs')->updateOrInsert(
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
            // Table pas encore créée — on ne fait que logger, pas de crash
            Log::debug('tondo_whatsapp_logs non disponible : ' . $e->getMessage());
        }

        return response('', 204);
    }

    // ── Signature Twilio (identique à WebhookController) ─────────────────────

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

        $calcule = base64_encode(hash_hmac('sha1', $data, $authToken, true));

        return hash_equals($calcule, $signature);
    }
}
