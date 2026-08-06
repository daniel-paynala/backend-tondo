<?php

namespace App\Services\WhatsApp\Contracts;

/**
 * Contrat commun d'envoi WhatsApp sortant, indépendant du fournisseur.
 *
 * Deux implémentations existent :
 *   - {@see \App\Services\WhatsApp\TwilioSenderService} (API REST Twilio)
 *   - {@see \App\Services\WhatsApp\MetaSenderService}   (Graph API Meta Cloud)
 *
 * L'implémentation active est résolue par le conteneur selon
 * `config('services.whatsapp.driver')` (voir AppServiceProvider). Les appelants
 * (cron de vérification des paiements, jobs de reçu) type-hintent cette
 * interface et reçoivent donc automatiquement le bon fournisseur.
 */
interface WhatsAppSender
{
    /**
     * Envoie un message texte simple.
     *
     * @param  string $to       Numéro E.164 du destinataire (ex : +24177123456).
     * @param  string $message  Corps du message (texte brut ou formatage *gras*).
     * @return bool             true si l'API répond 2xx, false sinon (jamais d'exception).
     */
    public function envoyer(string $to, string $message): bool;

    /**
     * Envoie un message texte accompagné d'un PDF (reçu de paiement).
     *
     * Le PDF doit être hébergé sur une URL https publiquement accessible.
     *
     * @param  string $to       Numéro E.164 du destinataire.
     * @param  string $message  Corps du message accompagnant le PDF.
     * @param  string $pdfUrl   URL publique du PDF.
     * @return bool             true si succès, false sinon.
     */
    public function envoyerAvecPdf(string $to, string $message, string $pdfUrl): bool;
}
