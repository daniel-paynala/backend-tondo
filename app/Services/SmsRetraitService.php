<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Envoi des SMS du retrait en espèces : code d'autorisation et confirmation.
 *
 * Passe directement par Wirepick, sans dépendre de l'agrégateur de paiement :
 * le texte est composé par Tonji (montant, agent, code), ce qu'un service de
 * vérification OTP clé en main ne permettrait pas.
 */
class SmsRetraitService
{
    /**
     * @throws \RuntimeException si Wirepick est mal configuré ou refuse l'envoi.
     */
    public function envoyer(string $numeroE164, string $texte, string $contexte): void
    {
        $destinataire = $numeroE164;
        $force        = trim((string) config('retrait.sms_destinataire_force'));

        // Détournement réservé aux environnements hors production : la base de
        // test contient des copies de numéros réels, qu'un essai ne doit jamais
        // atteindre. En production la variable est ignorée, même renseignée.
        $detourne = ! app()->environment('production') && $force !== '';
        if ($detourne) {
            $destinataire = $force;
        }

        // Résolu ici et non injecté : le constructeur de Wirepick lève si les
        // identifiants manquent, ce qui ferait échouer toutes les routes
        // d'agent — connexion comprise — au lieu du seul envoi.
        app(WirepickSmsService::class)->send($destinataire, $texte);

        Log::info('[retrait] SMS envoyé', [
            'contexte' => $contexte,
            'vers'     => app(ReceiptService::class)->maskPhone($numeroE164),
            'detourne' => $detourne,
        ]);
    }
}
