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

    /** Délai entre deux vérifications (secondes). */
    private const INTERVALLE_SECONDES = 10;

    /** Nombre maximum de tentatives avant timeout (18 × 10s = 3 minutes). */
    private const MAX_TENTATIVES      = 18;

    /**
     * @param  string $transId     Identifiant de transaction Airtel à vérifier.
     * @param  string $numeroWa    Numéro WhatsApp E.164 de l'utilisateur.
     * @param  string $projectId   UUID du projet (isolation multi-tenant).
     * @param  string $cagnotteRef Référence numérique courte de la cagnotte.
     * @param  int    $montant     Montant cotisé (FCFA), affiché dans la confirmation.
     * @param  string $prenom      Prénom de l'utilisateur (message de confirmation).
     * @param  string $userId      UUID de l'utilisateur Tondo.
     * @param  int    $tentative   Numéro de tentative courant (1 par défaut au premier dispatch).
     */
    public function __construct(
        private readonly string $transId,
        private readonly string $numeroWa,
        private readonly string $projectId,
        private readonly string $cagnotteRef,
        private readonly int    $montant,
        private readonly string $prenom,
        private readonly string $userId,
        private readonly int    $tentative = 1,
    ) {}

    /**
     * Vérifie le statut du paiement et agit en conséquence.
     *
     * – Succès  : envoie la confirmation + dispatche EnvoyerRecuJob avec délai.
     * – Échec   : envoie un message d'erreur + remet la session à 'menu'.
     * – Attente : se re-programme après INTERVALLE_SECONDES jusqu'à MAX_TENTATIVES.
     * – Timeout : réinitialise la session + informe l'utilisateur.
     *
     * @param  CotisationService   $cotisationSvc Interroge l'API agrégateur.
     * @param  SessionService      $sessionSvc    Gère l'état de conversation WhatsApp.
     * @param  TwilioSenderService $twilio        Envoie les messages sortants.
     * @param  ReceiptService      $receiptSvc    Génère les reçus PDF (via EnvoyerRecuJob).
     */
    public function handle(
        CotisationService  $cotisationSvc,
        SessionService     $sessionSvc,
        TwilioSenderService $twilio,
        ReceiptService     $receiptSvc,
    ): void {
        // Si la session a déjà été réinitialisée (l'utilisateur a navigué entre-temps), abandonner.
        if ($sessionSvc->etape($this->numeroWa) !== 'cotiser.attente') {
            Log::info('VerifierPaiementJob: session déjà réinitialisée, abandon', [
                'trans_id' => $this->transId,
            ]);
            return;
        }

        $statut = $cotisationSvc->verifierStatut($this->transId, $this->projectId);

        if ($statut === 'succes') {
            $sent = $this->envoyerSucces($twilio, $receiptSvc);
            if ($sent) {
                $sessionSvc->set($this->numeroWa, 'menu');
            }
            return;
        }

        if ($statut === 'echec') {
            $sent = $this->envoyerEchec($twilio);
            if ($sent) {
                $sessionSvc->set($this->numeroWa, 'menu');
            }
            return;
        }

        // Toujours en attente — vérifier si on a atteint la limite.
        if ($this->tentative >= self::MAX_TENTATIVES) {
            $sessionSvc->reset($this->numeroWa);
            $this->envoyerTimeout($twilio);
            return;
        }

        // Re-programmer le job après INTERVALLE_SECONDES avec le compteur incrémenté.
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

    /**
     * Envoie le message de confirmation de paiement puis dispatche le reçu PDF.
     *
     * La confirmation est envoyée immédiatement. Le PDF est envoyé dans un job
     * séparé avec 4 secondes de délai pour ne pas bloquer le message principal.
     *
     * @param  TwilioSenderService $twilio     Service d'envoi WhatsApp.
     * @param  ReceiptService      $receiptSvc Service de génération PDF (via job).
     * @return bool                            True si Twilio a accepté le message.
     */
    private function envoyerSucces(TwilioSenderService $twilio, ReceiptService $receiptSvc): bool
    {
        $cagnotte   = TondoCagnotte::where('reference', $this->cagnotteRef)->first();
        $montantFmt = number_format($this->montant, 0, ',', ' ');
        $titre      = $cagnotte?->titre ?? '—';
        $ref        = $cagnotte ? '#' . $cagnotte->reference : '';

        // 1. Confirmation immédiate — sans PDF pour ne pas bloquer la réponse.
        $texte = <<<TXT
        ✅ *Paiement confirmé !*

        Merci {$this->prenom} 🙏
        Votre cotisation de *{$montantFmt} FCFA* pour *{$titre} {$ref}* a été enregistrée.

        ————————————————
        🎉 *Que souhaitez-vous faire ?*

        1️⃣  *Cotiser*
        2️⃣  *Rejoindre* une cagnotte
        3️⃣  *Créer* une cagnotte
        4️⃣  *Gérer* mes cagnottes
        5️⃣  *Aide* & support

        _Tapez le numéro de votre choix._
        TXT;

        $sent = $twilio->envoyer($this->numeroWa, $texte);
        if (! $sent) {
            return false;
        }

        // 2. PDF en message séparé dès qu'il est généré.
        EnvoyerRecuJob::dispatch(
            numeroWa:    $this->numeroWa,
            userId:      $this->userId,
            cagnotteRef: $this->cagnotteRef,
            transId:     $this->transId,
            montant:     $this->montant,
        )->delay(now()->addSeconds(4));

        return true;
    }

    /**
     * Envoie un message d'erreur quand le paiement est refusé ou échoue côté opérateur.
     *
     * @param  TwilioSenderService $twilio Service d'envoi WhatsApp.
     * @return bool                        True si Twilio a accepté le message.
     */
    private function envoyerEchec(TwilioSenderService $twilio): bool
    {
        return $twilio->envoyer($this->numeroWa, <<<TXT
        ❌ *Paiement échoué ou refusé.*

        ⚠️ _Si vous constatez un prélèvement sur votre compte sans confirmation de notre part, contactez-nous immédiatement à support@tonji.ga._

        ————————————————
        🎉 *Que souhaitez-vous faire ?*

        1️⃣  *Cotiser*
        2️⃣  *Rejoindre* une cagnotte
        3️⃣  *Créer* une cagnotte
        4️⃣  *Gérer* mes cagnottes
        5️⃣  *Aide* & support

        _Tapez le numéro de votre choix._
        TXT);
    }

    /**
     * Informe l'utilisateur que le délai de confirmation de 3 minutes est dépassé.
     *
     * Appelle reset() sur la session avant de dispatcher ce message (dans handle()).
     *
     * @param  TwilioSenderService $twilio Service d'envoi WhatsApp.
     */
    private function envoyerTimeout(TwilioSenderService $twilio): void
    {
        $twilio->envoyer($this->numeroWa, <<<TXT
        ⏰ *Délai de 3 minutes dépassé.*

        Nous n'avons pas reçu de confirmation de votre paiement.

        ⚠️ _Si vous avez bien validé sur votre Mobile Money et qu'un prélèvement a eu lieu, contactez-nous à support@tondo.ga._

        _Tapez_ *#️⃣* _pour revenir au menu ou *1* pour réessayer._
        TXT);
    }
}
