<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Alerte envoyée aux admins quand un appel Paynala disburse échoue.
 *
 * Le solde de la cagnotte a déjà été décrémenté (réservation) — l'admin
 * doit vérifier manuellement si l'argent a bougé côté Paynala avant de
 * prendre toute action corrective.
 */
class DisbursementFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string $payoutId           UUID de la ligne tondo_payout créée en Phase 1.
     * @param  string $transId            Identifiant interne Tondo de la transaction.
     * @param  string $cagnotteReference  Référence numérique courte de la cagnotte.
     * @param  int    $montant            Montant du virement tenté (FCFA).
     * @param  string $numeroBeneficiaire Numéro E.164 du bénéficiaire du virement.
     * @param  string $idempotencyKey     Clé d'idempotence envoyée à Paynala (pour retry manuel).
     * @param  string $errorMessage       Message d'erreur retourné par l'API Paynala.
     */
    public function __construct(
        public readonly string $payoutId,
        public readonly string $transId,
        public readonly string $cagnotteReference,
        public readonly int    $montant,
        public readonly string $numeroBeneficiaire,
        public readonly string $idempotencyKey,
        public readonly string $errorMessage,
    ) {}

    /**
     * Définit l'objet et les métadonnées de l'email.
     *
     * Le préfixe [TONDO ALERTE] facilite le filtrage dans la boîte admin.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[TONDO ALERTE] Échec disbursement — intervention manuelle requise',
        );
    }

    /**
     * Définit le template de l'email.
     *
     * Le template texte brut `mail.disbursement-failed` affiche tous les champs
     * publics de cette classe via les variables disponibles dans la vue.
     */
    public function content(): Content
    {
        return new Content(text: 'mail.disbursement-failed');
    }

    /**
     * Pas de pièces jointes pour cet email d'alerte.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
