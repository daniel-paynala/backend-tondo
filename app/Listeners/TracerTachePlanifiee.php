<?php

namespace App\Listeners;

use App\Support\Registre;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Enregistre le passage de chaque tâche planifiée.
 *
 * Une tâche qui ne tourne plus ne produit rien — pas d'erreur, pas de log,
 * rien. C'est le pire cas : le transfert automatique de 18 h peut être muet
 * pendant une semaine sans que personne le remarque. Cet écouteur laisse une
 * trace à chaque passage ; la sonde de santé compare ensuite l'horodatage à la
 * cadence attendue.
 *
 * Écrit une ligne par commande, mise à jour à chaque fois : historiser chaque
 * passage produirait dix-sept mille lignes par jour rien que pour la tâche qui
 * tourne toutes les cinq secondes.
 */
class TracerTachePlanifiee
{
    /**
     * Fréquence maximale d'écriture, par commande.
     *
     * La tâche des paiements WhatsApp tourne toutes les cinq secondes : sans
     * ce pas, on écrirait en base plus souvent qu'on ne travaille.
     */
    private const PAS_ECRITURE_SECONDES = 60;

    public function finie(ScheduledTaskFinished $evenement): void
    {
        $this->tracer($evenement->task->command ?? '', 'succes', (int) round($evenement->runtime * 1000));
    }

    public function echouee(ScheduledTaskFailed $evenement): void
    {
        $this->tracer(
            $evenement->task->command ?? '',
            'echec',
            null,
            mb_substr($evenement->exception?->getMessage() ?? 'échec sans message', 0, 500),
        );
    }

    private function tracer(string $commandeBrute, string $statut, ?int $dureeMs = null, ?string $message = null): void
    {
        $commande = $this->nomCommande($commandeBrute);
        if ($commande === null) {
            return; // Tâche anonyme (fermeture) : rien à suivre.
        }

        try {
            $table = project_table('taches');
            $ligne = DB::table($table)->where('commande', $commande)->first();

            // Un succès récent n'a pas besoin d'être réécrit ; un échec, si :
            // c'est l'information qu'on veut voir remonter tout de suite.
            if ($ligne && $statut === 'succes'
                && now()->diffInSeconds($ligne->derniere_execution, true) < self::PAS_ECRITURE_SECONDES) {
                return;
            }

            if ($ligne) {
                DB::table($table)->where('commande', $commande)->update([
                    'derniere_execution' => now(),
                    'duree_ms'           => $dureeMs,
                    'statut'             => $statut,
                    'message'            => $message,
                    'passages'           => DB::raw('passages + 1'),
                    'echecs'             => DB::raw('echecs + ' . ($statut === 'echec' ? 1 : 0)),
                    'updated_at'         => now(),
                ]);

                return;
            }

            DB::table($table)->insert([
                'commande'           => $commande,
                'derniere_execution' => now(),
                'duree_ms'           => $dureeMs,
                'statut'             => $statut,
                'message'            => $message,
                'passages'           => 1,
                'echecs'             => $statut === 'echec' ? 1 : 0,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        } catch (\Throwable $e) {
            // Le suivi ne doit jamais faire tomber la tâche qu'il observe.
            Log::warning('[taches] trace impossible', ['commande' => $commande, 'erreur' => $e->getMessage()]);
        }
    }

    /**
     * Extrait le nom artisan de la ligne de commande exécutée, et ne garde que
     * les tâches déclarées au registre : une commande lancée à la main ne doit
     * pas créer de ligne fantôme.
     */
    private function nomCommande(string $brute): ?string
    {
        if (! preg_match('/artisan[\'"]? ([a-z0-9:_-]+)/i', $brute, $m)) {
            return null;
        }

        return isset(Registre::TACHES[$m[1]]) ? $m[1] : null;
    }
}
