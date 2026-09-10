<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Agrège la télémétrie brute en compteurs quotidiens, puis purge l'ancien.
 *
 * Planification : quotidienne à 02h00 (Africa/Libreville), heure creuse.
 *
 * Deux raisons de repasser sur une FENÊTRE et non sur la seule veille :
 *  – l'app bufferise et peut être hors ligne, donc des événements d'hier
 *    arrivent aujourd'hui ; sans recalcul, ils ne seraient jamais comptés ;
 *  – si le job saute une nuit, la nuit suivante rattrape sans intervention.
 *
 * L'agrégat est écrit en UPSERT sur la clé (projet, jour, nom, canal,
 * plateforme) : rejouer la commande autant de fois qu'on veut donne le même
 * résultat, elle ne cumule jamais sur elle-même.
 */
class AgregerEvenementsCommand extends Command
{
    protected $signature = 'tonji:agreger-evenements
                            {--jours=3 : Nombre de jours à recalculer, en remontant depuis aujourd\'hui}
                            {--retention=90 : Âge en jours au-delà duquel les lignes brutes sont purgées}
                            {--sans-purge : Agrège sans supprimer les lignes brutes}';

    protected $description = 'Agrège les événements de télémétrie en compteurs quotidiens et purge les lignes brutes anciennes.';

    public function handle(): int
    {
        $jours     = max(1, (int) $this->option('jours'));
        $retention = max(1, (int) $this->option('retention'));
        $projectId = Project::tondoId();

        // Heure locale : un événement du 10 à 23h à Libreville doit compter pour
        // le 10, pas pour le 11 comme le voudrait UTC.
        $depuis = now()->timezone('Africa/Libreville')->subDays($jours - 1)->startOfDay();

        $this->info("Agrégation depuis {$depuis->toDateString()} ({$jours} jour(s))…");

        $lignes = DB::table(project_table('evenements'))
            ->where('project_id', $projectId)
            ->where('occurred_at', '>=', $depuis)
            ->selectRaw("
                (occurred_at AT TIME ZONE 'Africa/Libreville')::date as jour,
                nom,
                canal,
                COALESCE(plateforme, '') as plateforme,
                COUNT(*) as compte,
                COUNT(DISTINCT user_id) as utilisateurs_uniques
            ")
            ->groupByRaw("1, nom, canal, COALESCE(plateforme, '')")
            ->get();

        $maintenant = now();
        $ecrits     = 0;

        foreach ($lignes as $l) {
            DB::table(project_table('evenements_jour'))->updateOrInsert(
                [
                    'project_id' => $projectId,
                    'jour'       => $l->jour,
                    'nom'        => $l->nom,
                    'canal'      => $l->canal,
                    'plateforme' => $l->plateforme,
                ],
                [
                    // `id` n'est fourni qu'à la création — updateOrInsert le
                    // laisse de côté quand la ligne existe déjà.
                    'id'                   => (string) Str::uuid(),
                    'compte'               => (int) $l->compte,
                    'utilisateurs_uniques' => (int) $l->utilisateurs_uniques,
                    'updated_at'           => $maintenant,
                    'created_at'           => $maintenant,
                ],
            );
            $ecrits++;
        }

        $this->info("  {$ecrits} agrégat(s) écrit(s).");

        if ($this->option('sans-purge')) {
            $this->line('  Purge ignorée (--sans-purge).');

            return self::SUCCESS;
        }

        // Purge APRÈS agrégation : dans cet ordre, une ligne purgée a forcément
        // déjà été comptée. L'inverse perdrait les données d'une journée.
        $limite   = now()->subDays($retention);
        $supprimes = DB::table(project_table('evenements'))
            ->where('project_id', $projectId)
            ->where('created_at', '<', $limite)
            ->delete();

        $this->info("  {$supprimes} ligne(s) brute(s) purgée(s) (> {$retention} jours).");

        if ($supprimes > 0) {
            Log::info('[telemetrie] purge des événements bruts', [
                'supprimes' => $supprimes,
                'retention' => $retention,
            ]);
        }

        return self::SUCCESS;
    }
}
