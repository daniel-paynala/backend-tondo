<?php

namespace Tests\Feature;

use App\Support\Registre;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * L'audit doit rester vert sur la branche.
 *
 * Ce test est le garde-fou du garde-fou : si quelqu'un réintroduit un préfixe
 * banni, désaligne un drapeau ou retire une tâche du planificateur, la suite
 * de tests échoue avant la revue.
 */
class AuditIntegriteTest extends TestCase
{
    public function test_l_audit_statique_ne_releve_aucune_anomalie(): void
    {
        // Mode non strict : les avertissements (clés absentes d'un poste de
        // développement) ne doivent pas faire échouer la suite.
        $this->artisan('tonji:audit')->assertSuccessful();
    }

    public function test_aucun_prefixe_banni_n_est_emis_par_le_code(): void
    {
        $emis = [];
        foreach (['app', 'routes'] as $dossier) {
            $iterateur = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dossier)),
            );
            foreach ($iterateur as $fichier) {
                if (! $fichier->isFile() || $fichier->getExtension() !== 'php') {
                    continue;
                }
                $chemin = ltrim(str_replace(base_path(), '', $fichier->getPathname()), '/');
                if (in_array($chemin, Registre::FICHIERS_EXCLUS, true)) {
                    continue;
                }
                foreach (Registre::PREFIXES_BANNIS as $banni) {
                    if (str_contains(file_get_contents($fichier->getPathname()) ?: '', "'{$banni}")) {
                        $emis[] = "{$banni} dans {$chemin}";
                    }
                }
            }
        }

        $this->assertSame([], $emis, "Préfixes bannis encore présents :\n" . implode("\n", $emis));
    }

    public function test_chaque_tache_planifiee_est_declaree_au_registre(): void
    {
        $planifiees = [];
        foreach (app(Schedule::class)->events() as $evenement) {
            if (preg_match('/artisan[\'"]? ([a-z0-9:_-]+)/i', $evenement->command ?? '', $m)) {
                $planifiees[] = $m[1];
            }
        }

        $this->assertNotEmpty($planifiees, 'Aucune tâche planifiée détectée — le contrôle ne vérifierait rien.');
        $this->assertSame(
            [],
            array_values(array_diff($planifiees, array_keys(Registre::TACHES))),
            'Des tâches tournent sans être déclarées dans le registre.',
        );
        $this->assertSame(
            [],
            array_values(array_diff(array_keys(Registre::TACHES), $planifiees)),
            'Des tâches déclarées ne sont plus planifiées.',
        );
    }
}
