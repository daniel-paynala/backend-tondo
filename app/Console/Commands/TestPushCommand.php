<?php

namespace App\Console\Commands;

use App\Models\TondoUser;
use App\Services\FcmService;
use Illuminate\Console\Command;

/**
 * Diagnostic FCM : envoie un push de test aux appareils d'un utilisateur et
 * affiche la réponse BRUTE de FCM (statut HTTP + corps) pour chaque token.
 *
 * Interprétation :
 *   – « non configuré »        → FCM_PROJECT_ID / FCM_CREDENTIALS_PATH absents,
 *                                ou fichier de compte de service introuvable.
 *   – « auth = false »         → le compte de service ne permet pas d'obtenir un
 *                                access token (clé privée / droits invalides).
 *   – 0 token                  → cet utilisateur n'a AUCUN appareil enregistré :
 *                                l'app n'a pas (encore) envoyé son token FCM
 *                                après connexion, ou notifications refusées.
 *   – 200                      → FCM a accepté : si rien ne s'affiche, c'est côté
 *                                device (permission, focus, Ne pas déranger).
 *   – 404 (UNREGISTERED)       → token mort (app désinstallée) — purgé auto.
 *
 * Usage :
 *   php artisan tonji:test-push <uuid|numero>
 *   php artisan tonji:test-push 077050946 --titre="Test" --corps="Coucou"
 */
class TestPushCommand extends Command
{
    protected $signature = 'tonji:test-push
                            {cible : UUID du user OU son numéro Mobile Money}
                            {--titre=Test Tonji : notification}
                            {--corps=Si tu vois ceci, les notifications fonctionnent.}';

    protected $description = 'Envoie un push FCM de test et affiche la réponse brute (diagnostic).';

    public function handle(FcmService $fcm): int
    {
        // 1) Config présente ?
        if (! $fcm->estConfigure()) {
            $this->error('FCM non configuré : FCM_PROJECT_ID / FCM_CREDENTIALS_PATH manquants, ou fichier de compte de service introuvable.');

            return self::FAILURE;
        }

        // 2) Résolution de la cible → user.
        $cible = (string) $this->argument('cible');
        $user  = $this->resoudreUser($cible);

        if (! $user) {
            $this->error("Utilisateur introuvable pour « {$cible} » (ni UUID ni numéro connu).");

            return self::FAILURE;
        }

        $this->line("Cible : <info>{$user->prenom} {$user->nom}</info> · numéro {$user->numero}");
        $this->line("user_id : <info>{$user->id}</info>");
        $this->newLine();

        // 3) Envoi + affichage de la réponse brute par token.
        $res = $fcm->envoyerBrutUser($user->id, (string) $this->option('titre'), (string) $this->option('corps'));

        if (($res['auth'] ?? true) === false) {
            $this->error('→ Authentification FCM échouée : le compte de service ne permet pas d\'obtenir un access token (clé privée / droits).');

            return self::FAILURE;
        }

        $nbTokens = $res['tokens'] ?? 0;
        if ($nbTokens === 0) {
            $this->warn('→ 0 appareil enregistré pour cet utilisateur.');
            $this->warn('  Côté app : après connexion, POST /api/mobile/devices doit envoyer le token FCM + la permission notifications doit être accordée.');

            return self::SUCCESS;
        }

        foreach ($res['resultats'] ?? [] as $r) {
            $status = $r['status'] ?? 0;
            $this->line("Token {$r['token']} ({$r['plateforme']}) → HTTP <info>{$status}</info>");
            $this->line('  ' . ($r['body'] ?? '(vide)'));

            if ($status >= 200 && $status < 300) {
                $this->info('  → OK : FCM a pris en charge le push.');
            } elseif ($status === 401 || $status === 403) {
                $this->error('  → Access token refusé (droits du compte de service).');
            } elseif ($status === 404) {
                $this->warn('  → Token mort (app désinstallée) — purgé automatiquement.');
            } else {
                $this->error('  → Échec (voir le corps ci-dessus).');
            }
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
