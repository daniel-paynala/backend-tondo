<?php

namespace App\Services;

use App\Models\TondoProjectConfig;

/**
 * Source de vérité pour la config tarifaire d'un projet.
 *
 * Priorité : ligne DB (`tondo_project_config`) > `config/airtel.php`.
 * Cela permet de modifier les taux depuis le dashboard admin sans
 * redéploiement ; en l'absence de ligne DB, les valeurs d'environnement
 * font office de défaut sûr.
 */
class TondoConfigService
{
    /**
     * Retourne la config Airtel pour le projet donné, prête à être passée
     * à `AirtelFeesCalculator`. Format identique à `config/airtel.php`.
     */
    public function getAirtelConfig(string $projectId): array
    {
        $row = TondoProjectConfig::where('project_id', $projectId)->first();

        if ($row) {
            return $row->toAirtelArray();
        }

        // Fallback : config/airtel.php (env vars)
        return config('airtel');
    }

    /**
     * Persiste une config pour le projet. Crée la ligne si elle n'existe pas.
     */
    public function updateAirtelConfig(string $projectId, array $data): TondoProjectConfig
    {
        return TondoProjectConfig::upsertForProject($projectId, $data);
    }
}
