<?php

namespace App\Jobs\WhatsApp;

use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use App\Services\ReceiptService;
use App\Services\WhatsApp\TwilioSenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Génère le reçu PDF et l'envoie en message WhatsApp séparé.
 * Dispatché juste après la confirmation de paiement pour ne pas
 * bloquer l'envoi du message principal.
 */
class EnvoyerRecuJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  string  $numeroWa    Numéro WhatsApp E.164 du destinataire.
     * @param  ?string $userId      UUID de l'utilisateur Tondo (null si compte light).
     * @param  ?string $cagnotteRef Référence numérique courte de la cagnotte.
     * @param  string  $transId     Identifiant de transaction (pour le reçu PDF).
     * @param  int     $montant     Montant net cotisé (FCFA), affiché sur le reçu.
     */
    public function __construct(
        private readonly string  $numeroWa,
        private readonly ?string $userId,
        private readonly ?string $cagnotteRef,
        private readonly string  $transId,
        private readonly int     $montant,
    ) {}

    /**
     * Génère le reçu PDF et envoie l'URL par message WhatsApp.
     *
     * En cas d'échec (génération PDF ou envoi Twilio), l'erreur est logguée
     * sans relancer le job — l'absence de reçu est non-bloquante pour l'utilisateur.
     *
     * @param  TwilioSenderService $twilio     Service d'envoi de messages WhatsApp.
     * @param  ReceiptService      $receiptSvc Service de génération de reçus PDF.
     */
    public function handle(TwilioSenderService $twilio, ReceiptService $receiptSvc): void
    {
        // Charger les entités depuis la DB — null toléré si introuvables (pas de crash).
        $user     = $this->userId      ? TondoUser::find($this->userId)                               : null;
        $cagnotte = $this->cagnotteRef ? TondoCagnotte::where('reference', $this->cagnotteRef)->first() : null;

        try {
            $pdfUrl = $receiptSvc->generer($user, $cagnotte, [
                'trans_id'    => $this->transId,
                'montant_net' => $this->montant,
            ], 'WhatsApp');

            // Envoyer l'URL du PDF dans un message séparé (le message de confirmation
            // principal a déjà été envoyé par VerifierPaiementJob).
            $twilio->envoyer($this->numeroWa, "📄 *Votre reçu Tonji :*\n{$pdfUrl}");
        } catch (\Throwable $e) {
            // Échec non bloquant — l'utilisateur a déjà reçu la confirmation.
            Log::error('EnvoyerRecuJob: échec', [
                'err'      => $e->getMessage(),
                'trans_id' => $this->transId,
            ]);
        }
    }
}
