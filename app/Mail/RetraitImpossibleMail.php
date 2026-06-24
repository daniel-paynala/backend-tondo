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

    /**
     * @param  string $cagnotteReference Référence numérique courte de la tontine.
     * @param  string $cagnotteTitre     Nom de la tontine affiché dans l'email.
     * @param  int    $cycle             Numéro du cycle en cours.
     * @param  int    $nombrePayes       Membres ayant cotisé ce cycle.
     * @param  int    $nombreTotal       Nombre total de membres attendus.
     * @param  array  $nonPayes          Liste des retardataires : [['nom','prenom','numero_masque'],…].
     * @param  string $dateRetrait       Date prévue du retrait (format 'Y-m-d').
     */
    public function __construct(
        public readonly string $cagnotteReference,
        public readonly string $cagnotteTitre,
        public readonly int    $cycle,
        public readonly int    $nombrePayes,
        public readonly int    $nombreTotal,
        public readonly array  $nonPayes,
        public readonly string $dateRetrait,
    ) {}

    /**
     * Définit l'objet et les métadonnées de l'email.
     *
     * Le préfixe [TONDO] facilite le filtrage ; le gérant et les admins
     * reçoivent cet email pour prendre des mesures (relance des retardataires).
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[TONDO] Retrait suspendu — cotisations incomplètes',
        );
    }

    /**
     * Définit le template de l'email.
     *
     * Le template texte `mail.retrait-impossible` liste les membres
     * non payés avec leur numéro masqué pour respecter la confidentialité.
     */
    public function content(): Content
    {
        return new Content(text: 'mail.retrait-impossible');
    }

    /**
     * Pas de pièces jointes pour cet email de notification.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
