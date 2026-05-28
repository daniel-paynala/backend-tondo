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

    public function __construct(
        public readonly string $payoutId,
        public readonly string $transId,
        public readonly string $cagnotteReference,
        public readonly int    $montant,
        public readonly string $numeroBeneficiaire,
        public readonly string $idempotencyKey,
        public readonly string $errorMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[TONDO ALERTE] Échec disbursement — intervention manuelle requise',
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.disbursement-failed');
    }

    public function attachments(): array
    {
        return [];
    }
}
