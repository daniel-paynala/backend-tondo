<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Alerte envoyée au gérant et aux admins quand un retrait automatique
 * ne peut pas être effectué faute de cotisations complètes.
 */
class RetraitImpossibleMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $cagnotteReference,
        public readonly string $cagnotteTitre,
        public readonly int    $cycle,
        public readonly int    $nombrePayes,
        public readonly int    $nombreTotal,
        public readonly array  $nonPayes,   // [['nom','prenom','numero_masque'],…]
        public readonly string $dateRetrait,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[TONDO] Retrait suspendu — cotisations incomplètes',
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.retrait-impossible');
    }

    public function attachments(): array
    {
        return [];
    }
}
