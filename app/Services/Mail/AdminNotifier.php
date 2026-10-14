<?php

namespace App\Services\Mail;

use App\Models\TondoAdmin;
use Illuminate\Support\Facades\Log;

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
        // ── Hors production, on n'écrit à personne ────────────────────────
        //
        // Ce service passe par l'API Mailgun, pas par le pilote de messagerie
        // de Laravel : `MAIL_MAILER=log` ne l'arrête donc PAS. La recette
        // envoyait de vrais courriels aux administrateurs à chaque transfert
        // refusé ou signalement de test — et ni le planificateur ni la
        // configuration ne pouvaient le prévenir, puisque ces alertes partent
        // au fil des requêtes.
        //
        // Le garde-fou est ici, au seul endroit par lequel tout passe. Il se
        // lève avec `ADMIN_ALERTES_HORS_PROD=true`, pour le jour où l'on veut
        // vérifier la chaîne depuis la recette.
        if (! app()->environment('production')
            && ! config('services.admin_alertes.hors_prod', false)) {
            Log::info('Alerte administrateur retenue hors production', [
                'sujet'     => $subject,
                'categorie' => $categorie,
            ]);

            return 0;
        }

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
