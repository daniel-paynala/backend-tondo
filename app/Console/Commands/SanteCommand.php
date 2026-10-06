<?php

namespace App\Console\Commands;

use App\Services\Mail\AdminNotifier;
use App\Support\Sante;
use Illuminate\Console\Command;

/**
 * Sonde de santé du système, lisible en console ou envoyée par courriel.
 *
 * Avec --alerter, la commande ne dit rien tant que tout va bien : un rapport
 * quotidien qui arrive même vert finit par ne plus être ouvert, et c'est
 * précisément le jour où il est rouge qu'on ne le lit pas.
 */
class SanteCommand extends Command
{
    protected $signature = 'tonji:sante
                            {--alerter : N\'envoie un courriel aux admins qu\'en cas d\'anomalie}
                            {--json : Sort le rapport brut}';

    protected $description = 'État réel du système : tâches planifiées, paiements, réglages, schéma';

    public function handle(Sante $sante, AdminNotifier $notifier): int
    {
        $rapport = $sante->rapport();

        if ($this->option('json')) {
            $this->line(json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $symboles = [
            Sante::OK => '<fg=green>OK      </>',
            Sante::ALERTE => '<fg=yellow>ALERTE  </>',
            Sante::CRITIQUE => '<fg=red>CRITIQUE</>',
        ];

        $this->info('Santé Tonji — ' . $rapport['environnement'] . ' — ' . now()->format('d/m/Y H:i'));
        $this->newLine();
        foreach ($rapport['controles'] as $c) {
            $this->line($symboles[$c['etat']] . '  ' . str_pad(mb_substr($c['titre'], 0, 30), 32) . $c['detail']);
        }
        $this->newLine();

        $problemes = array_filter($rapport['controles'], fn ($c) => $c['etat'] !== Sante::OK);

        if ($rapport['etat'] === Sante::OK) {
            $this->info('Tout est au vert.');
        } elseif ($rapport['etat'] === Sante::ALERTE) {
            $this->warn(count($problemes) . ' point(s) à regarder.');
        } else {
            $this->error(count($problemes) . ' point(s), dont au moins un critique.');
        }

        if ($this->option('alerter') && $rapport['etat'] !== Sante::OK) {
            $this->alerter($notifier, $rapport, $problemes);
            $this->line('Alerte envoyée aux administrateurs.');
        }

        // Le code de sortie reste 0 : cette commande observe, elle ne juge pas
        // un déploiement. C'est `tonji:audit` qui bloque une livraison.
        return self::SUCCESS;
    }

    /** @param array<string, mixed> $rapport */
    private function alerter(AdminNotifier $notifier, array $rapport, array $problemes): void
    {
        $lignes = array_map(
            fn ($c) => '<li><b>' . e($c['titre']) . '</b> — ' . e($c['detail']) . '</li>',
            $problemes,
        );

        $sujet = 'Santé du système — '
            . ($rapport['etat'] === Sante::CRITIQUE ? 'point critique' : 'à regarder');

        $notifier->notifier(
            \App\Models\Project::tondoId(),
            'problemes',
            $sujet,
            $sujet,
            '<p>La sonde de santé a relevé ' . count($problemes) . ' point(s) sur l\'environnement <b>'
            . e($rapport['environnement']) . '</b> :</p><ul>' . implode('', $lignes) . '</ul>',
            'Ouvrir le dashboard',
            config('services.admin_dashboard_url'),
        );
    }
}
