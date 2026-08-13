<?php

namespace App\Services\Mail;

use App\Models\TondoAdmin;

/**
 * Notifie par e-mail les admins d'un projet qui ont activé la catégorie
 * concernée dans leurs préférences (notif_prefs). Contenu mis en forme par
 * EmailLayout (gabarit pro Tonji), envoyé via MailgunSender.
 */
class AdminNotifier
{
    public function __construct(private MailgunSender $sender) {}

    /**
     * @param  string  $categorie  'signalements' | 'problemes' | 'autre'
     * @return int Nombre d'e-mails acceptés par Mailgun.
     */
    public function notifier(
        string $projectId,
        string $categorie,
        string $subject,
        string $titre,
        string $corpsHtml,
        ?string $ctaLabel = null,
        ?string $ctaUrl = null,
    ): int {
        $admins = TondoAdmin::query()
            ->where('project_id', $projectId)
            ->where('actif', true)
            ->get();

        $html = EmailLayout::render($titre, $corpsHtml, $ctaLabel, $ctaUrl, $subject);
        $envoyes = 0;

        foreach ($admins as $admin) {
            // Respecte la préférence de l'admin (clé absente = activé par défaut).
            if (($admin->notifPrefs()[$categorie] ?? true) === false) {
                continue;
            }
            if ($this->sender->envoyer($admin->email, $subject, $html)) {
                $envoyes++;
            }
        }

        return $envoyes;
    }
}
