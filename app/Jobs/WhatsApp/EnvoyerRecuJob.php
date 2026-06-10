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

    public function __construct(
        private readonly string  $numeroWa,
        private readonly ?string $userId,
        private readonly ?string $cagnotteRef,
        private readonly string  $transId,
        private readonly int     $montant,
    ) {}

    public function handle(TwilioSenderService $twilio, ReceiptService $receiptSvc): void
    {
        $user     = $this->userId     ? TondoUser::find($this->userId)                              : null;
        $cagnotte = $this->cagnotteRef ? TondoCagnotte::where('reference', $this->cagnotteRef)->first() : null;

        try {
            $pdfUrl = $receiptSvc->generer($user, $cagnotte, [
                'trans_id'    => $this->transId,
                'montant_net' => $this->montant,
            ], 'WhatsApp');

            $twilio->envoyer($this->numeroWa, "📄 *Votre reçu Tonji :*\n{$pdfUrl}");
        } catch (\Throwable $e) {
            Log::error('EnvoyerRecuJob: échec', [
                'err'      => $e->getMessage(),
                'trans_id' => $this->transId,
            ]);
        }
    }
}
