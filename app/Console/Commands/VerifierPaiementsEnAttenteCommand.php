<?php

namespace App\Console\Commands;

use App\Models\TondoCagnotte;
use App\Models\TondoPaiementEnAttente;
use App\Models\TondoUser;
use App\Services\ReceiptService;
use App\Services\WhatsApp\CotisationService;
use App\Services\WhatsApp\SessionService;
use App\Services\WhatsApp\TwilioSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VerifierPaiementsEnAttenteCommand extends Command
{
    protected $signature   = 'tondo:verifier-paiements';
    protected $description = 'Vérifie le statut des paiements WhatsApp en attente et envoie les confirmations.';

    // Abandon après 4 minutes (cron toutes les minutes → ~3 tentatives avant timeout)
    private const TIMEOUT_MINUTES = 4;

    public function handle(
        CotisationService   $cotisationSvc,
        SessionService      $sessionSvc,
        TwilioSenderService $twilio,
        ReceiptService      $receiptSvc,
    ): void {
        $expireAt = now()->subMinutes(self::TIMEOUT_MINUTES);

        // Paiements trop vieux : timeout.
        $expires = TondoPaiementEnAttente::where('created_at', '<', $expireAt)->get();
        foreach ($expires as $p) {
            $deleted = TondoPaiementEnAttente::where('trans_id', $p->trans_id)->delete();
            if (! $deleted) continue;

            $sessionSvc->reset($p->numero_wa);
            $twilio->envoyer($p->numero_wa, <<<TXT
            ⏰ *Délai de 3 minutes dépassé.*

            Nous n'avons pas reçu de confirmation de votre paiement.

            ⚠️ _Si vous avez bien validé sur votre Mobile Money et qu'un prélèvement a eu lieu, contactez-nous à support@tonji.ga._

            _Tapez_ *#️⃣* _pour revenir au menu ou *1* pour réessayer._
            TXT);
        }

        // Paiements encore dans le délai : vérifier le statut.
        $pendants = TondoPaiementEnAttente::where('created_at', '>=', $expireAt)->get();
        foreach ($pendants as $p) {
            // Si la session a changé (user a tapé OK entre-temps), purger.
            if ($sessionSvc->etape($p->numero_wa) !== 'cotiser.attente') {
                TondoPaiementEnAttente::where('trans_id', $p->trans_id)->delete();
                continue;
            }

            try {
                $statut = $cotisationSvc->verifierStatut($p->trans_id, $p->project_id);
            } catch (\Throwable $e) {
                Log::error('tondo:verifier-paiements: erreur verifierStatut', [
                    'trans_id' => $p->trans_id,
                    'err'      => $e->getMessage(),
                ]);
                continue;
            }

            if ($statut === 'succes') {
                // Supprimer en premier — évite un double-envoi si OK et cron se chevauchent.
                $deleted = TondoPaiementEnAttente::where('trans_id', $p->trans_id)->delete();
                if (! $deleted) continue;

                $sessionSvc->set($p->numero_wa, 'menu');
                $this->envoyerSucces($twilio, $receiptSvc, $p);

            } elseif ($statut === 'echec') {
                $deleted = TondoPaiementEnAttente::where('trans_id', $p->trans_id)->delete();
                if (! $deleted) continue;

                $sessionSvc->set($p->numero_wa, 'menu');
                $twilio->envoyer($p->numero_wa, <<<TXT
                ❌ *Paiement échoué ou refusé.*

                ⚠️ _Si vous constatez un prélèvement sur votre compte sans confirmation de notre part, contactez-nous immédiatement à support@tonji.ga._

                ————————————————
                🎉 *Que souhaitez-vous faire ?*

                1️⃣  *Cotiser*
                2️⃣  *Rejoindre* une tontine
                3️⃣  *Créer* (Tontine ou Cagnotte)
                4️⃣  *Gérer* (Tontine ou Cagnotte)
                5️⃣  *Aide* & support

                _Tapez le numéro de votre choix._
                TXT);
            }
            // Statut 'en_attente' → on laisse en DB, le prochain tick reprend.
        }
    }

    private function envoyerSucces(
        TwilioSenderService $twilio,
        ReceiptService $receiptSvc,
        TondoPaiementEnAttente $p,
    ): void {
        $cagnotte   = TondoCagnotte::where('reference', $p->cagnotte_ref)->first();
        $user       = TondoUser::find($p->user_id);
        $montantFmt = number_format($p->montant, 0, ',', ' ');
        $titre      = $cagnotte?->titre ?? '—';
        $ref        = $cagnotte ? '#' . $cagnotte->reference : '';

        $pdfUrl    = null;
        $ligneRecu = '';
        try {
            $pdfUrl    = $receiptSvc->generer($user, $cagnotte, [
                'trans_id'    => $p->trans_id,
                'montant_net' => $p->montant,
            ], 'WhatsApp');
            $ligneRecu = "\n📄 *Votre reçu :* {$pdfUrl}";
            Log::info('tondo:verifier-paiements: reçu généré', [
                'trans_id' => $p->trans_id,
                'pdf_url'  => $pdfUrl,
            ]);
        } catch (\Throwable $e) {
            Log::error('tondo:verifier-paiements: échec génération reçu', [
                'trans_id' => $p->trans_id,
                'err'      => $e->getMessage(),
            ]);
        }

        $twilio->envoyer($p->numero_wa, <<<TXT
        ✅ *Paiement confirmé !*

        Merci {$p->prenom} 🙏
        Votre paiement de *{$montantFmt} FCFA* pour *{$titre} {$ref}* a été enregistré.{$ligneRecu}

        ————————————————
        🎉 *Que souhaitez-vous faire ?*

        1️⃣  *Cotiser*
        2️⃣  *Rejoindre* une tontine
        3️⃣  *Créer* (Tontine ou Cagnotte)
        4️⃣  *Gérer* (Tontine ou Cagnotte)
        5️⃣  *Aide* & support

        _Tapez le numéro de votre choix._
        TXT);
    }
}
