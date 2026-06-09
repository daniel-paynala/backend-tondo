<?php

namespace App\Jobs\WhatsApp;

use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use App\Services\ReceiptService;
use App\Services\WhatsApp\CotisationService;
use App\Services\WhatsApp\SessionService;
use App\Services\WhatsApp\TwilioSenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Vérifie le statut d'un paiement WhatsApp toutes les 30 secondes.
 * Se re-programme jusqu'à 6 fois (= 3 minutes max).
 *
 * Si succès → envoie la confirmation + le reçu PDF via Twilio outbound.
 * Si échec  → envoie un message d'avertissement.
 * Si timeout après 3 min → informe l'utilisateur.
 */
class VerifierPaiementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const INTERVALLE_SECONDES = 30;
    private const MAX_TENTATIVES      = 6;   // 6 × 30s = 3 min

    public function __construct(
        private readonly string $transId,
        private readonly string $numeroWa,     // E.164 du compte WhatsApp de l'utilisateur
        private readonly string $projectId,
        private readonly string $cagnotteRef,
        private readonly int    $montant,
        private readonly string $prenom,
        private readonly string $userId,
        private readonly int    $tentative = 1,
    ) {}

    public function handle(
        CotisationService  $cotisationSvc,
        SessionService     $sessionSvc,
        TwilioSenderService $twilio,
        ReceiptService     $receiptSvc,
    ): void {
        // Si la session a déjà été réinitialisée (user a tapé OK entre-temps), on abandonne.
        if ($sessionSvc->etape($this->numeroWa) !== 'cotiser.attente') {
            Log::info('VerifierPaiementJob: session déjà réinitialisée, abandon', [
                'trans_id' => $this->transId,
            ]);
            return;
        }

        $statut = $cotisationSvc->verifierStatut($this->transId, $this->projectId);

        if ($statut === 'succes') {
            $sessionSvc->reset($this->numeroWa);
            $this->envoyerSucces($twilio, $receiptSvc);
            return;
        }

        if ($statut === 'echec') {
            $sessionSvc->reset($this->numeroWa);
            $this->envoyerEchec($twilio);
            return;
        }

        // Toujours en attente
        if ($this->tentative >= self::MAX_TENTATIVES) {
            $sessionSvc->reset($this->numeroWa);
            $this->envoyerTimeout($twilio);
            return;
        }

        // Re-programmer dans 30 secondes
        self::dispatch(
            transId:     $this->transId,
            numeroWa:    $this->numeroWa,
            projectId:   $this->projectId,
            cagnotteRef: $this->cagnotteRef,
            montant:     $this->montant,
            prenom:      $this->prenom,
            userId:      $this->userId,
            tentative:   $this->tentative + 1,
        )->delay(now()->addSeconds(self::INTERVALLE_SECONDES));
    }

    // ── Notifications sortantes ───────────────────────────────────────────────

    private function envoyerSucces(TwilioSenderService $twilio, ReceiptService $receiptSvc): void
    {
        $user     = TondoUser::find($this->userId);
        $cagnotte = TondoCagnotte::where('reference', $this->cagnotteRef)->first();
        $montantFmt = number_format($this->montant, 0, ',', ' ');
        $titre    = $cagnotte?->titre ?? '—';
        $ref      = $cagnotte ? '#' . $cagnotte->reference : '';

        try {
            $pdfUrl = $receiptSvc->generer($user, $cagnotte, [
                'trans_id'    => $this->transId,
                'montant_net' => $this->montant,
            ], 'WhatsApp');
        } catch (\Throwable $e) {
            Log::error('VerifierPaiementJob: échec génération PDF', ['err' => $e->getMessage()]);
            $pdfUrl = null;
        }

        $texte = <<<TXT
        ✅ *Paiement confirmé !*

        Merci {$this->prenom} 🙏
        Votre cotisation de *{$montantFmt} FCFA* pour *{$titre} {$ref}* a été enregistrée.

        📄 Votre reçu PDF Tondo est joint à ce message.

        _Tapez_ *#* _pour revenir au menu._
        TXT;

        if ($pdfUrl) {
            $twilio->envoyerAvecPdf($this->numeroWa, $texte, $pdfUrl);
        } else {
            $twilio->envoyer($this->numeroWa, $texte);
        }
    }

    private function envoyerEchec(TwilioSenderService $twilio): void
    {
        $twilio->envoyer($this->numeroWa, <<<TXT
        ❌ *Paiement échoué ou refusé.*

        ⚠️ _Si vous constatez un prélèvement sur votre compte sans confirmation de notre part, contactez-nous immédiatement à support@tondo.ga. Nous traiterons votre remboursement sous 24h._

        _Tapez_ *#* _pour revenir au menu._
        TXT);
    }

    private function envoyerTimeout(TwilioSenderService $twilio): void
    {
        $twilio->envoyer($this->numeroWa, <<<TXT
        ⏰ *Délai de 3 minutes dépassé.*

        Nous n'avons pas reçu de confirmation de votre paiement.

        ⚠️ _Si vous avez bien validé sur votre Mobile Money et qu'un prélèvement a eu lieu, contactez-nous à support@tondo.ga._

        _Tapez_ *#* _pour revenir au menu ou *1* pour réessayer._
        TXT);
    }
}
