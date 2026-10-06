<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\ReglementFrais;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Règle les parts de frais accumulées.
 *
 * La plupart des parts partent déjà seules, juste après le reversement qui les
 * a créées. Cette tâche existe pour celles qui n'ont pas pu : 1 % de 3 000 FCFA
 * fait 30 FCFA, sous le plancher de l'opérateur. Elles s'accumulent, et ce
 * passage les envoie dès que le cumul d'un compte franchit le seuil.
 *
 * Toutes les 15 minutes : assez souvent pour qu'un compte ne traîne pas une
 * créance une journée entière, assez rare pour ne pas marteler l'opérateur à
 * vide — un passage sans rien à régler ne fait qu'une somme en base.
 *
 * Isolé PAR COMPTE : un compte mal configuré ne doit pas empêcher les autres
 * d'être réglés.
 */
class ReglerFraisCommand extends Command
{
    protected $signature = 'tonji:regler-frais
        {--dry-run : Affiche ce qui serait réglé sans rien décaisser}';

    protected $description = 'Règle les parts de frais accumulées au-dessus du plancher de décaissement';

    public function handle(ReglementFrais $reglement): int
    {
        // Environnement de test : aucun décaissement réel. On s'arrête avant de
        // parcourir les comptes, plutôt que de produire un échec par compte.
        if (\App\Services\PaynalaPaymentService::operationsReellesBloquees()) {
            $this->warn('Décaissements réels bloqués sur cet environnement (PAYNALA_OPERATIONS_REELLES).');

            return self::SUCCESS;
        }

        $sec      = (bool) $this->option('dry-run');
        $projectId = Project::tondoId();

        $comptes = DB::table(project_table('frais_comptes'))
            ->where('project_id', $projectId)
            ->where('actif', true)
            ->orderBy('libelle')
            ->get(['id', 'libelle', 'numero_tel', 'type_paynala']);

        if ($comptes->isEmpty()) {
            // Aucun compte configuré = aucune répartition en vigueur. Ce n'est
            // pas une anomalie, c'est l'état par défaut du produit.
            $this->line('Aucun compte de frais configuré — rien à régler.');

            return self::SUCCESS;
        }

        $regles = 0;
        $total  = 0;

        foreach ($comptes as $compte) {
            $du = (int) DB::table(project_table('frais_dus'))
                ->where('compte_id', $compte->id)
                ->where('statut', 'du')
                ->whereNull('trans_id')
                ->sum('montant');

            if ($du === 0) {
                continue;
            }

            if ($sec) {
                $this->line("  [dry-run] {$compte->libelle} : {$du} FCFA en attente.");
                continue;
            }

            try {
                $r = $reglement->reglerCompte($compte, $projectId);
            } catch (\Throwable $e) {
                // Isolé par compte : les suivants doivent être réglés quand
                // même. Une part reste due, elle repassera.
                $this->error("  {$compte->libelle} : erreur — {$e->getMessage()}");
                continue;
            }

            if ($r['regle']) {
                $this->info("  {$compte->libelle} : {$r['montant']} FCFA réglés · {$r['trans_id']}");
                $regles++;
                $total += $r['montant'];
            } else {
                $this->line("  {$compte->libelle} : non réglé — {$r['raison']}");
            }
        }

        $this->line("{$regles} compte(s) réglé(s), " . number_format($total, 0, ',', ' ') . ' FCFA.');

        return self::SUCCESS;
    }
}
