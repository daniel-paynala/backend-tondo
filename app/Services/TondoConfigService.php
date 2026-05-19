<?php

namespace App\Services;

use App\Models\TondoProjectConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Source de vérité pour la config tarifaire d'un projet.
 *
 * Frais de retrait + commission Paynala : DB (`tondo_project_config`) > `config/airtel.php`.
 * La commission et les tranches sont configurables par opérateur / pays.
 */
class TondoConfigService
{
    /**
     * Config complète pour un opérateur/pays donné.
     * Format compatible avec AirtelFeesCalculator.
     */
    public function getOperatorConfig(
        string $projectId,
        string $operateur = 'airtel',
        string $pays = 'GA',
    ): array {
        $row = TondoProjectConfig::where('project_id', $projectId)
            ->where('operateur', $operateur)
            ->where('pays', $pays)
            ->first();

        if ($row) {
            return $row->toConfigArray();
        }

        // Fallback : défauts config/airtel.php
        $c = config('airtel');
        return [
            'operateur'          => $operateur,
            'pays'               => $pays,
            'indicatif'          => null,
            'prefixes'           => [],
            'commission_paynala' => (float) $c['commission_paynala'],
            'plafond_par_envoi'  => (int) $c['plafond_par_envoi'],
            'plafond_journalier' => (int) $c['plafond_journalier'],
            'tranches'           => $c['tranches'] ?? [],
        ];
    }

    /** Tous les opérateurs configurés pour un projet. */
    public function listOperatorConfigs(string $projectId): Collection
    {
        return TondoProjectConfig::where('project_id', $projectId)
            ->orderBy('pays')
            ->orderBy('operateur')
            ->get()
            ->map(fn ($r) => $r->toConfigArray());
    }

    /** Upsert d'une config opérateur. */
    public function updateOperatorConfig(
        string $projectId,
        string $operateur,
        string $pays,
        array  $data,
    ): TondoProjectConfig {
        return TondoProjectConfig::upsert($projectId, $operateur, $pays, $data);
    }

    /** Bascule actif ↔ inactif. */
    public function toggleOperatorConfig(
        string $projectId,
        string $operateur,
        string $pays,
    ): void {
        TondoProjectConfig::where('project_id', $projectId)
            ->where('operateur', $operateur)
            ->where('pays', $pays)
            ->update(['actif' => DB::raw('NOT actif')]);
    }

    /** Supprime une config opérateur. */
    public function deleteOperatorConfig(
        string $projectId,
        string $operateur,
        string $pays,
    ): void {
        TondoProjectConfig::where('project_id', $projectId)
            ->where('operateur', $operateur)
            ->where('pays', $pays)
            ->delete();
    }
}
