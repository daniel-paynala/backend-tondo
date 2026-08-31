<?php

namespace App\Console\Commands;

use App\Models\TondoUser;
use App\Services\OneSignalService;
use Illuminate\Console\Command;

/**
 * Diagnostic OneSignal : envoie un push de test à un utilisateur et affiche la
 * réponse BRUTE d'OneSignal (statut HTTP + corps).
 *
 * Sert à comprendre pourquoi un push « n'arrive pas » :
 *   – 401 / 403                → clé API invalide/dépréciée (régénérer `os_v2_app_…`).
 *   – 200 + recipients = 0     → l'external_id (UUID user) n'est pas abonné :
 *                                le device n'a pas appelé `OneSignal.login()`,
 *                                ou l'utilisateur a refusé les notifications.
 *   – 200 + errors.invalid_aliases → même cause (alias inconnu d'OneSignal).
 *   – 200 + recipients ≥ 1     → OneSignal a bien pris en charge : si rien ne
 *                                s'affiche, c'est côté device (permission, focus…).
 *
 * Usage :
 *   php artisan tonji:test-push <uuid|numero>
 *   php artisan tonji:test-push 077730634 --titre="Test" --corps="Coucou"
 */
class TestPushCommand extends Command
{
    protected $signature = 'tonji:test-push
                            {cible : UUID du user OU son numéro Mobile Money}
                            {--titre=Test Tonji : notification}
                            {--corps=Si tu vois ceci, les notifications fonctionnent.}';

    protected $description = 'Envoie un push OneSignal de test et affiche la réponse brute (diagnostic).';

    public function handle(OneSignalService $onesignal): int
    {
        // 1) Config présente ?
        if (! $onesignal->estConfigure()) {
            $this->error('OneSignal non configuré : ONESIGNAL_APP_ID / ONESIGNAL_REST_API_KEY manquent dans le .env.');

            return self::FAILURE;
        }

        // 2) Résolution de la cible → external_id (= UUID du user).
        $cible = (string) $this->argument('cible');
        $user  = $this->resoudreUser($cible);

        if (! $user) {
            $this->error("Utilisateur introuvable pour « {$cible} » (ni UUID ni numéro connu).");

            return self::FAILURE;
        }

        $this->line("Cible : <info>{$user->prenom} {$user->nom}</info> · numéro {$user->numero}");
        $this->line("external_id envoyé à OneSignal : <info>{$user->id}</info>");
        $this->newLine();

        // 3) Envoi + affichage de la réponse brute.
        $res = $onesignal->envoyerBrut(
            [$user->id],
            (string) $this->option('titre'),
            (string) $this->option('corps'),
            ['type' => 'test_push'],
        );

        if (isset($res['error'])) {
            $this->error("Exception réseau : {$res['error']}");

            return self::FAILURE;
        }

        $status = $res['status'] ?? 0;
        $json   = is_array($res['json'] ?? null) ? $res['json'] : [];
        $recipients = $json['recipients'] ?? null;
        $errors     = $json['errors'] ?? null;

        $this->line("HTTP status : <info>{$status}</info>");
        $this->line('Réponse     : ' . ($res['body'] ?? '(vide)'));
        $this->newLine();

        // 4) Interprétation lisible.
        if ($status === 401 || $status === 403) {
            $this->error('→ Clé API rejetée. Régénère une clé « os_v2_app_… » (dashboard OneSignal > Keys & IDs) et mets à jour ONESIGNAL_REST_API_KEY.');
        } elseif ($status >= 400) {
            $this->error('→ Payload/app_id rejeté par OneSignal (voir le corps ci-dessus).');
        } elseif ($recipients === 0 || ! empty($errors)) {
            $this->warn('→ 0 destinataire réel : cet utilisateur n\'a AUCUN device abonné.');
            $this->warn('  Vérifie côté app : OneSignal.login(userId) appelé au démarrage + permission notifications accordée.');
        } elseif ($recipients !== null && $recipients >= 1) {
            $this->info("→ OK : OneSignal a pris en charge le push ({$recipients} destinataire·s).");
            $this->line('  Si rien ne s\'affiche : c\'est côté device (permission système, app au premier plan, Ne pas déranger…).');
        } else {
            $this->line('→ Réponse inattendue — inspecte le corps brut ci-dessus.');
        }

        return self::SUCCESS;
    }

    /**
     * Résout la cible en user : d'abord par UUID (id), sinon par numéro
     * (avec quelques variantes de format courantes au Gabon).
     */
    private function resoudreUser(string $cible): ?TondoUser
    {
        // Ressemble à un UUID ? → lookup direct par id.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $cible)) {
            return TondoUser::find($cible);
        }

        // Sinon on tente par numéro. On normalise en gardant les chiffres.
        $chiffres = preg_replace('/\D+/', '', $cible);
        $variantes = array_unique(array_filter([
            $cible,
            $chiffres,
            $chiffres ? '+' . $chiffres : null,
            // 077730634 <→ 24177730634 (indicatif Gabon)
            $chiffres && str_starts_with($chiffres, '0') ? '241' . substr($chiffres, 1) : null,
            $chiffres && str_starts_with($chiffres, '241') ? '0' . substr($chiffres, 3) : null,
        ]));

        return TondoUser::whereIn('numero', $variantes)->first();
    }
}
