<?php

namespace App\Jobs;

use App\Services\PaiementMarchandNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Expédie les messages d'un paiement marchand, hors du cycle de la requête.
 *
 * En file et non en ligne : la réponse au client ne doit pas attendre Wirepick
 * ni Mailgun. Un décaissement Airtel prend déjà plusieurs secondes, et un
 * opérateur de SMS lent ferait croire à un paiement bloqué alors que l'argent
 * est parti.
 *
 * **Un seul essai.** La prise de notification a déjà eu lieu avant la mise en
 * file : rejouer ce job enverrait un second « vous avez reçu 150 000 », et le
 * marchand croirait à deux paiements. Le parti pris est au plus une fois — un
 * échec se lit dans les journaux et se rejoue à la main, en connaissance de
 * cause.
 */
class NotifierPaiementMarchand implements ShouldQueue
{
    use Queueable;

    /** Pas de reprise automatique : voir l'en-tête de classe. */
    public int $tries = 1;

    public function __construct(public string $payoutId) {}

    public function handle(PaiementMarchandNotifier $notifier): void
    {
        $notifier->envoyer($this->payoutId);
    }
}
