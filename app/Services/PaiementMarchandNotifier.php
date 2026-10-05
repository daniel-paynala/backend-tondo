<?php

namespace App\Services;

use App\Contracts\PushNotifier;
use App\Jobs\NotifierPaiementMarchand;
use App\Services\Mail\MailgunSender;
use App\Support\MessagePaiementMarchand;
use App\Support\Registre;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Prévenir le marchand qu'il a été payé, et le payeur que c'est parti.
 *
 * Deux responsabilités séparées à dessein :
 *  - {@see signaler()} prend la main sur la notification, de façon atomique ;
 *  - {@see envoyer()} compose et expédie, depuis la file.
 *
 * Cette séparation est le cœur du dispositif. La confirmation d'un paiement
 * peut être atteinte par plusieurs chemins — l'appel client aujourd'hui, la
 * régularisation d'un décaissement resté en suspens demain. Si chacun envoyait
 * directement, deux chemins concurrents enverraient deux « vous avez reçu
 * 150 000 », et le marchand croirait à deux paiements.
 */
class PaiementMarchandNotifier
{
    public function __construct(
        private PushNotifier $push,
        private SmsRetraitService $sms,
        private MailgunSender $mail,
    ) {}

    /**
     * Prend la notification en charge, une seule fois, et la met en file.
     *
     * La prise est un UPDATE conditionnel : Postgres garantit qu'un seul
     * appelant concurrent voit une ligne affectée. Pas de lecture suivie d'une
     * écriture, donc pas de fenêtre entre les deux — c'est précisément ce qui
     * manquait à la garde d'idempotence du double-crédit du 2026-08-31.
     *
     * Appelable depuis n'importe quel chemin qui confirme un paiement : les
     * suivants ne feront rien.
     */
    public function signaler(string $payoutId): void
    {
        $pris = DB::table(project_table('payout'))
            ->where('id', $payoutId)
            ->where('statut', 'succes')
            ->whereNotNull('marchand_id')
            ->whereNull('notifie_at')
            ->update(['notifie_at' => now(), 'updated_at' => now()]);

        if ($pris !== 1) {
            return;
        }

        NotifierPaiementMarchand::dispatch($payoutId);
    }

    /**
     * Compose et expédie. Appelé depuis la file, jamais directement.
     *
     * Un canal qui échoue n'empêche pas l'autre : le SMS et l'e-mail disent la
     * même chose, en recevoir un seul vaut mieux que rien.
     */
    public function envoyer(string $payoutId): void
    {
        $donnees = $this->charger($payoutId);
        if ($donnees === null) {
            Log::warning('[paiement_marchand] payout introuvable ou non marchand', [
                'payout_id' => $payoutId,
            ]);

            return;
        }

        $texte = MessagePaiementMarchand::pourMarchand([
            'montant'     => $donnees->montant,
            'reference'   => Registre::court($donnees->trans_id) ?? $donnees->trans_id,
            'cagnotte'    => $donnees->cagnotte_titre,
            'payeur'      => $this->nomPayeur($donnees),
            'date_heure'  => MessagePaiementMarchand::dateHeure($donnees->date_creation),
            'url_portail' => config('services.portail_marchand_url'),
        ]);

        $this->envoyerSms($donnees, $texte);
        $this->envoyerMail($donnees, $texte);
        $this->prevenirPayeur($donnees);
    }

    /** SMS au marchand. Obligatoire : c'est son seul canal immédiat. */
    private function envoyerSms(object $d, string $texte): void
    {
        try {
            $this->sms->envoyer($d->marchand_numero, $texte, 'paiement_marchand');
        } catch (\Throwable $e) {
            Log::error('[paiement_marchand] SMS non parti', [
                'payout_id' => $d->id,
                'erreur'    => $e->getMessage(),
            ]);
        }
    }

    /** E-mail au marchand, quand la fiche en porte un. */
    private function envoyerMail(object $d, string $texte): void
    {
        $adresse = trim((string) ($d->marchand_email ?? ''));
        if ($adresse === '') {
            return;
        }

        try {
            $this->mail->envoyer(
                $adresse,
                'Tonji — paiement de ' . MessagePaiementMarchand::montant($d->montant) . ' FCFA reçu',
                '<pre style="font-family:inherit;font-size:15px">' . e($texte) . '</pre>',
            );
        } catch (\Throwable $e) {
            Log::error('[paiement_marchand] e-mail non parti', [
                'payout_id' => $d->id,
                'erreur'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notification poussée au payeur — pas de SMS pour lui.
     *
     * Il a l'application, la ligne dans son historique et son reçu : un SMS
     * facturé n'apporterait rien de plus.
     */
    private function prevenirPayeur(object $d): void
    {
        if ($d->user_id === null) {
            return;
        }

        [$titre, $corps] = MessagePaiementMarchand::pourPayeur([
            'montant'  => $d->montant,
            'marchand' => $d->marchand_nom,
        ]);

        try {
            $this->push->notifyOne($d->user_id, $titre, $corps, [
                'type'        => 'paiement_marchand',
                'cagnotte_id' => $d->cagnotte_id,
                'trans_id'    => $d->trans_id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[paiement_marchand] push non partie', [
                'payout_id' => $d->id,
                'erreur'    => $e->getMessage(),
            ]);
        }
    }

    /** Tout ce qu'il faut pour composer, en une requête. */
    private function charger(string $payoutId): ?object
    {
        $payout    = project_table('payout');
        $marchands = project_table('marchands');
        $cagnottes = project_table('cagnottes');

        return DB::table("{$payout} as p")
            ->join("{$marchands} as m", 'm.id', '=', 'p.marchand_id')
            ->join("{$cagnottes} as c", 'c.id', '=', 'p.cagnotte_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.id', $payoutId)
            ->first([
                'p.id', 'p.montant', 'p.trans_id', 'p.date_creation',
                'p.user_id', 'p.cagnotte_id',
                'm.nom as marchand_nom', 'm.numero_tel as marchand_numero',
                'm.contact_email as marchand_email',
                'c.titre as cagnotte_titre',
                'u.nom as payeur_nom', 'u.prenom as payeur_prenom',
            ]);
    }

    /**
     * Nom du payeur tel qu'il apparaît sur le message du marchand.
     *
     * Un paiement peut venir d'un parcours sans compte Tonji : on ne laisse
     * alors pas la ligne vide, elle serait plus troublante qu'absente.
     */
    private function nomPayeur(object $d): string
    {
        $nom = trim(mb_strtoupper((string) ($d->payeur_nom ?? '')) . ' '
            . ucfirst(mb_strtolower((string) ($d->payeur_prenom ?? ''))));

        return $nom === '' ? 'Client Tonji' : $nom;
    }
}
