<?php

namespace App\Contracts;

/**
 * Contrat d'envoi de notifications push.
 *
 * Abstrait le fournisseur derrière une interface : les appelants (contrôleurs,
 * commandes) type-hintent `PushNotifier` et reçoivent l'implémentation liée
 * dans le container (voir AppServiceProvider). On peut ainsi changer de
 * fournisseur (FCM aujourd'hui, un autre demain) sans toucher aux ~20 sites
 * d'appel.
 *
 * Implémentation courante : {@see \App\Services\FcmService} (FCM/APNs en direct).
 */
interface PushNotifier
{
    /**
     * Envoie une notification à un ou plusieurs utilisateurs Tondo.
     *
     * @param  string[] $userIds  UUIDs Tondo. Vide → rien n'est envoyé.
     * @param  string   $titleFr  Titre (français).
     * @param  string   $bodyFr   Corps (français).
     * @param  array    $data     Métadonnées transmises à l'app (type, ids…).
     */
    public function notify(array $userIds, string $titleFr, string $bodyFr, array $data = []): void;

    /**
     * Raccourci : notifie un seul utilisateur.
     */
    public function notifyOne(string $userId, string $titleFr, string $bodyFr, array $data = []): void;
}
