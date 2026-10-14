<?php

namespace App\Console\Commands;

use App\Services\Mail\AdminNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les écosystèmes sont-ils restés étanches ?
 *
 * Les pays sont séparés (décision du 2026-10-14) mais partagent un seul
 * `project_id` : la frontière est tenue par du CODE, pas par la structure.
 * Une requête qui l'oublierait ne casserait rien — elle laisserait passer, en
 * silence. Personne ne le verrait, et l'argent serait dans le mauvais pays.
 *
 * Cet audit est la contrepartie de ce choix. Il ne fait pas confiance : il
 * cherche les traces d'une fuite, table par table, partout où une frontière
 * peut être franchie.
 *
 * Distinct de {@see AuditCommand}, qui vérifie la cohérence générale du
 * système. Celui-ci ne regarde qu'une chose, et la regarde à fond.
 */
class EtancheiteCommand extends Command
{
    protected $signature = 'tonji:etancheite
                            {--details : Liste les lignes fautives, pas seulement leur nombre}
                            {--alerter : Écrit aux administrateurs si une fuite est trouvée}';

    protected $description = 'Cherche les fuites entre écosystèmes pays (cotisations, encaissements, décaissements, membres, marchands)';

    /** @var array<int, array{titre: string, nombre: int, lignes: array<int, string>}> */
    private array $fuites = [];

    /** @var array<int, string> */
    private array $impasses = [];

    public function handle(AdminNotifier $notifier): int
    {
        $cagnottes = project_table('cagnottes');

        if (! Schema::hasTable($cagnottes) || ! Schema::hasColumn($cagnottes, 'pays')) {
            $this->error('cagnottes.pays absente — le SQL 046 n\'est pas joué.');

            return self::FAILURE;
        }

        $this->cotisations();
        $this->encaissements();
        $this->membres();
        $this->decaissements();
        $this->marchands();
        $this->divergences();
        $this->impasses();

        return $this->restituer($notifier);
    }

    // ── Les fuites : une frontière franchie ─────────────────────────────────

    /** Une cotisation versée depuis un autre pays que celui de la collecte. */
    private function cotisations(): void
    {
        $this->croiserAvecUtilisateur(
            project_table('paiements'),
            'Cotisations venues d\'un autre pays',
        );
    }

    /** Un encaissement initié depuis un autre pays. */
    private function encaissements(): void
    {
        $this->croiserAvecUtilisateur(
            project_table('payin'),
            'Encaissements initiés depuis un autre pays',
        );
    }

    /** Un membre inscrit à une collecte d'un autre pays. */
    private function membres(): void
    {
        $this->croiserAvecUtilisateur(
            project_table('participants'),
            'Membres inscrits à une collecte d\'un autre pays',
        );
    }

    /**
     * Le tronc commun : une table qui porte `user_id` et `cagnotte_id`.
     *
     * Le pays de l'utilisateur et celui de la collecte doivent coïncider. Les
     * lignes dont l'un des deux est inconnu ne sont PAS comptées comme fuites :
     * elles sont signalées à part, comme impasses. Confondre les deux ferait
     * crier à la fraude là où il n'y a qu'une donnée manquante.
     */
    private function croiserAvecUtilisateur(string $table, string $titre): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable('users')) {
            return;
        }

        $cagnottes = project_table('cagnottes');

        $lignes = DB::table("{$table} as t")
            ->join("{$cagnottes} as c", 'c.id', '=', 't.cagnotte_id')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->whereNotNull('c.pays')
            ->whereNotNull('u.pays')
            ->whereColumn('c.pays', '!=', 'u.pays')
            ->limit(50)
            ->get(['t.id', 'c.reference', 'c.pays as pays_collecte', 'u.pays as pays_utilisateur']);

        $this->noter($titre, $lignes->map(
            fn ($l) => "collecte {$l->reference} ({$l->pays_collecte}) ← utilisateur {$l->pays_utilisateur}"
        )->all());
    }

    /**
     * Un décaissement vers un numéro d'un autre pays.
     *
     * Ici on ne peut pas s'appuyer sur un `user_id` : le bénéficiaire peut
     * être un tiers dont on n'a que le numéro. Le pays se déduit donc de
     * l'indicatif, en s'appuyant sur ceux déclarés en configuration.
     */
    private function decaissements(): void
    {
        $payout = project_table('payout');
        if (! Schema::hasTable($payout)) {
            return;
        }

        $cagnottes = project_table('cagnottes');
        $lignes = [];

        foreach ($this->indicatifs() as $indicatif => $pays) {
            $trouvees = DB::table("{$payout} as p")
                ->join("{$cagnottes} as c", 'c.id', '=', 'p.cagnotte_id')
                ->whereNotNull('c.pays')
                ->where('c.pays', '!=', $pays)
                ->where('p.numero_tel', 'like', $indicatif . '%')
                ->limit(50)
                ->get(['p.trans_id', 'c.reference', 'c.pays as pays_collecte']);

            foreach ($trouvees as $l) {
                $lignes[] = "{$l->trans_id} : collecte {$l->reference} ({$l->pays_collecte}) → numéro {$pays}";
            }
        }

        $this->noter('Décaissements vers un numéro d\'un autre pays', $lignes);
    }

    /** Un marchand payé depuis une collecte d'un autre pays. */
    private function marchands(): void
    {
        $payout    = project_table('payout');
        $marchands = project_table('marchands');

        if (! Schema::hasTable($payout) || ! Schema::hasTable($marchands)) {
            return;
        }

        $cagnottes = project_table('cagnottes');
        $lignes = [];

        foreach ($this->indicatifs() as $indicatif => $pays) {
            $trouvees = DB::table("{$payout} as p")
                ->join("{$cagnottes} as c", 'c.id', '=', 'p.cagnotte_id')
                ->join("{$marchands} as m", 'm.id', '=', 'p.marchand_id')
                ->whereNotNull('c.pays')
                ->where('c.pays', '!=', $pays)
                ->where('m.numero_tel', 'like', $indicatif . '%')
                ->limit(50)
                ->get(['m.nom', 'c.reference', 'c.pays as pays_collecte']);

            foreach ($trouvees as $l) {
                $lignes[] = "{$l->nom} ({$pays}) payé par la collecte {$l->reference} ({$l->pays_collecte})";
            }
        }

        $this->noter('Marchands payés depuis une collecte d\'un autre pays', $lignes);
    }

    /**
     * Une collecte dont le pays a divergé de son organisateur.
     *
     * Il est recopié à la création et censé être immuable. Un écart signifie
     * qu'on l'a modifié après coup — ou que l'organisateur a changé de pays,
     * ce qui ne devrait pas arriver davantage.
     */
    private function divergences(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $cagnottes = project_table('cagnottes');

        $lignes = DB::table("{$cagnottes} as c")
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->whereNotNull('c.pays')
            ->whereNotNull('u.pays')
            ->whereColumn('c.pays', '!=', 'u.pays')
            ->limit(50)
            ->get(['c.reference', 'c.pays as pays_collecte', 'u.pays as pays_gerant']);

        $this->noter('Collectes dont le pays diffère de leur organisateur', $lignes->map(
            fn ($l) => "collecte {$l->reference} : {$l->pays_collecte} alors que son organisateur est en {$l->pays_gerant}"
        )->all());
    }

    // ── Les impasses : une donnée manquante, pas une fuite ──────────────────

    private function impasses(): void
    {
        $cagnottes = project_table('cagnottes');

        if ($n = DB::table($cagnottes)->whereNull('pays')->count()) {
            $this->impasses[] = "{$n} collecte(s) sans pays : personne ne pourra y cotiser.";
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'pays')) {
            if ($n = DB::table('users')->whereNull('pays')->count()) {
                $this->impasses[] = "{$n} compte(s) sans pays : ils ne peuvent ni créer ni cotiser.";
            }
        }

        $config = project_table('project_config');
        if (Schema::hasColumn($config, 'capacites')) {
            if ($n = DB::table($config)->whereNull('capacites')->count()) {
                $this->impasses[] = "{$n} opérateur(s) sans capacités décrites : ils ne savent rien faire.";
            }
        }
    }

    // ── Outillage ───────────────────────────────────────────────────────────

    /**
     * Les indicatifs déclarés, du plus long au plus court.
     *
     * L'ordre compte : un indicatif court préfixe parfois un plus long, et
     * tester « 22 » avant « 221 » rattacherait un numéro au mauvais pays.
     *
     * @return array<string, string> indicatif (avec +) => pays
     */
    private function indicatifs(): array
    {
        $map = [];
        foreach (DB::table(project_table('project_config'))->get(['indicatif', 'pays']) as $c) {
            if ($c->indicatif !== null && $c->pays !== null) {
                $map['+' . ltrim((string) $c->indicatif, '+')] = $c->pays;
            }
        }

        uksort($map, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $map;
    }

    /** @param array<int, string> $lignes */
    private function noter(string $titre, array $lignes): void
    {
        if ($lignes === []) {
            return;
        }

        $this->fuites[] = ['titre' => $titre, 'nombre' => count($lignes), 'lignes' => $lignes];
    }

    private function restituer(AdminNotifier $notifier): int
    {
        $this->info('Étanchéité des écosystèmes — ' . now()->format('d/m/Y H:i'));
        $this->newLine();

        foreach ($this->impasses as $impasse) {
            $this->warn('  ! ' . $impasse);
        }

        if ($this->fuites === []) {
            $this->newLine();
            $this->info('Aucune fuite. Les écosystèmes sont étanches.');

            return self::SUCCESS;
        }

        $total = 0;
        foreach ($this->fuites as $fuite) {
            $total += $fuite['nombre'];
            $this->newLine();
            $this->error("  {$fuite['titre']} : {$fuite['nombre']}");

            if ($this->option('details')) {
                foreach ($fuite['lignes'] as $ligne) {
                    $this->line('      ' . $ligne);
                }
            }
        }

        $this->newLine();
        $this->error("{$total} ligne(s) traversent une frontière.");

        if (! $this->option('details')) {
            $this->line('Relancez avec --details pour voir lesquelles.');
        }

        if ($this->option('alerter')) {
            $this->alerter($notifier, $total);
        }

        return self::FAILURE;
    }

    private function alerter(AdminNotifier $notifier, int $total): void
    {
        $projectId = DB::table(project_table('cagnottes'))->value('project_id');
        if ($projectId === null) {
            return;
        }

        $corps = '<p>Des lignes traversent la frontière entre écosystèmes pays. '
            . 'Les pays sont censés être étanches.</p><ul>';
        foreach ($this->fuites as $fuite) {
            $corps .= "<li><strong>{$fuite['titre']}</strong> : {$fuite['nombre']}</li>";
        }
        $corps .= '</ul><p>Détail : <code>php artisan tonji:etancheite --details</code></p>';

        $envoyes = $notifier->notifier(
            $projectId,
            'problemes',
            "Tonji — {$total} ligne(s) franchissent une frontière pays",
            'Étanchéité des écosystèmes percée',
            $corps,
        );

        $this->line("  {$envoyes} administrateur(s) notifié(s).");
    }
}
