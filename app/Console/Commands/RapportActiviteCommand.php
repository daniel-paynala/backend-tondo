<?php

namespace App\Console\Commands;

use App\Services\Mail\AdminNotifier;
use App\Support\RapportActivite;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rapport d'activité, lisible en console ou envoyé par courriel.
 *
 * Il part MÊME quand tout va bien, à l'inverse de la sonde de santé. Ce n'est
 * pas une alerte : c'est le chiffre régulier qui permet de voir qu'un jour
 * sort de l'ordinaire. Un rapport qui n'arriverait qu'en cas d'anomalie ne
 * servirait à rien — il n'y aurait rien à quoi comparer.
 */
class RapportActiviteCommand extends Command
{
    protected $signature = 'tonji:rapport-activite
                            {--jours=1 : Nombre de jours couverts, en remontant depuis hier soir}
                            {--envoyer : Envoie le rapport aux administrateurs}
                            {--json : Sort le rapport brut}';

    protected $description = 'Activité du système : cotisations, transferts, paiements, créations';

    public function handle(RapportActivite $rapports, AdminNotifier $notifier): int
    {
        $jours = max(1, (int) $this->option('jours'));

        // La veille entière, heure de Libreville. Pas « les 24 dernières
        // heures » : un rapport doit couvrir des journées closes, sinon deux
        // exécutions au même moment de la semaine ne se comparent pas.
        $fin   = Carbon::now('Africa/Libreville')->startOfDay()->subSecond();
        $debut = $fin->copy()->subDays($jours - 1)->startOfDay();

        $projectId = DB::table(project_table('cagnottes'))->value('project_id');
        if ($projectId === null) {
            $this->warn('Aucun projet en base — rien à rapporter.');

            return self::SUCCESS;
        }

        $rapport = $rapports->pour($projectId, $debut, $fin);

        if ($this->option('json')) {
            $this->line(json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->afficher($rapport, $debut, $fin, $jours);

        if ($this->option('envoyer')) {
            $envoyes = $notifier->notifier(
                $projectId,
                'rapports',
                'Tonji — activité ' . $this->libellePeriode($debut, $fin, $jours),
                'Activité ' . $this->libellePeriode($debut, $fin, $jours),
                $this->html($rapport),
            );

            $this->info($envoyes . ' administrateur(s) notifié(s).');
        }

        return self::SUCCESS;
    }

    private function libellePeriode(Carbon $debut, Carbon $fin, int $jours): string
    {
        return $jours === 1
            ? 'du ' . $debut->format('d/m/Y')
            : 'du ' . $debut->format('d/m') . ' au ' . $fin->format('d/m/Y');
    }

    private function fcfa(int $montant): string
    {
        return number_format($montant, 0, ',', ' ') . ' FCFA';
    }

    private function afficher(array $r, Carbon $debut, Carbon $fin, int $jours): void
    {
        $this->info('Activité Tonji — ' . $this->libellePeriode($debut, $fin, $jours));
        $this->newLine();

        $c = $r['cotisations'];
        $this->line('<fg=green>Cotisations</>');
        $this->line('  nombre       : ' . $c['nombre']);
        $this->line('  montant      : ' . $this->fcfa($c['montant']));
        $this->line('  panier moyen : ' . $this->fcfa($c['moyenne']));
        if (isset($c['taux_reussite'])) {
            $this->line('  tentatives   : ' . $c['tentatives']
                . ' (' . ($c['taux_reussite'] ?? '—') . ' % abouties)');
        }

        $this->newLine();
        $s = $r['sorties'];
        $this->line('<fg=green>Sorties d\'argent</>');
        if ($s['transferts'] !== null) {
            $this->line('  transferts   : ' . $s['transferts']['nombre']
                . ' — ' . $this->fcfa($s['transferts']['montant']));
            $this->line('  marchands    : ' . $s['marchands']['nombre']
                . ' — ' . $this->fcfa($s['marchands']['montant']));
            $this->line('  en échec     : ' . $s['en_echec']);
        } else {
            $this->line('  (table absente)');
        }

        $this->newLine();
        $this->line('<fg=green>Créations</>');
        $this->line('  collectes    : ' . $r['creations']['collectes']);
        $this->line('  comptes      : ' . ($r['creations']['comptes'] ?? '—'));
    }

    private function html(array $r): string
    {
        $c = $r['cotisations'];
        $s = $r['sorties'];

        $ligne = fn (string $libelle, string $valeur) => '<tr>'
            . '<td style="padding:6px 12px 6px 0;color:#4A5568">' . $libelle . '</td>'
            . '<td style="padding:6px 0;font-weight:700;text-align:right">' . $valeur . '</td>'
            . '</tr>';

        $html = '<h3 style="margin:16px 0 8px">Cotisations</h3><table style="width:100%">'
            . $ligne('Nombre', (string) $c['nombre'])
            . $ligne('Montant collecté', $this->fcfa($c['montant']))
            . $ligne('Panier moyen', $this->fcfa($c['moyenne']));

        if (isset($c['taux_reussite'])) {
            $html .= $ligne('Tentatives', $c['tentatives'] . ' (' . ($c['taux_reussite'] ?? '—') . ' % abouties)');
        }
        $html .= '</table>';

        if ($s['transferts'] !== null) {
            $html .= '<h3 style="margin:16px 0 8px">Sorties d\'argent</h3><table style="width:100%">'
                . $ligne('Transferts', $s['transferts']['nombre'] . ' — ' . $this->fcfa($s['transferts']['montant']))
                . $ligne('Paiements marchands', $s['marchands']['nombre'] . ' — ' . $this->fcfa($s['marchands']['montant']))
                . $ligne('En échec', (string) $s['en_echec'])
                . '</table>';
        }

        return $html . '<h3 style="margin:16px 0 8px">Créations</h3><table style="width:100%">'
            . $ligne('Collectes ouvertes', (string) $r['creations']['collectes'])
            . $ligne('Nouveaux comptes', (string) ($r['creations']['comptes'] ?? '—'))
            . '</table>';
    }
}
