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
        // Priorité : config en base de données (multi-tenant, par projet).
        $row = TondoProjectConfig::where('project_id', $projectId)
            ->where('operateur', $operateur)
            ->where('pays', $pays)
            ->first();

        if ($row) {
            // toConfigArray() normalise le modèle Eloquent vers le format attendu
            // par AirtelFeesCalculator (tranches, plafonds, commission).
            return $row->toConfigArray();
        }

        // Fallback : valeurs par défaut du fichier config/airtel.php.
        // Utilisé en développement ou si le projet n'a pas encore de config DB.
        $c = config('airtel');
        return [
            'operateur'          => $operateur,
            'pays'               => $pays,
            'indicatif'          => null,  // Pas d'indicatif configuré → détection impossible.
            'prefixes'           => [],    // Aucun préfixe → aucun opérateur détectable.
            'commission_paynala' => (float) $c['commission_paynala'],
            'plafond_par_envoi'  => (int) $c['plafond_par_envoi'],
            'plafond_journalier' => (int) $c['plafond_journalier'],
            'tranches'           => $c['tranches'] ?? [],
        ];
    }

    /**
     * Retourne toutes les configurations d'opérateurs enregistrées pour un projet,
     * triées par pays puis par opérateur.
     *
     * @return Collection<int, array>  Collection de tableaux au format toConfigArray().
     */
    public function listOperatorConfigs(string $projectId): Collection
    {
        return TondoProjectConfig::where('project_id', $projectId)
            ->orderBy('pays')
            ->orderBy('operateur')
            ->get()
            ->map(fn ($r) => $r->toConfigArray()); // Normalise chaque ligne vers le format standard.
    }

    /**
     * Crée ou met à jour (upsert) la configuration d'un opérateur pour un projet.
     *
     * La clé de déduplication est (project_id, operateur, pays).
     *
     * @param  array $data  Champs à écrire (tranches, commission_paynala, plafonds…).
     * @return TondoProjectConfig  L'instance créée ou mise à jour.
     */
    public function updateOperatorConfig(
        string $projectId,
        string $operateur,
        string $pays,
        array  $data,
    ): TondoProjectConfig {
        return TondoProjectConfig::upsert($projectId, $operateur, $pays, $data);
    }

    /**
     * Bascule le champ `actif` de la configuration opérateur (actif ↔ inactif).
     *
     * Utilise NOT SQL pour éviter une course critique (read-modify-write).
     */
    public function toggleOperatorConfig(
        string $projectId,
        string $operateur,
        string $pays,
    ): void {
        TondoProjectConfig::where('project_id', $projectId)
            ->where('operateur', $operateur)
            ->where('pays', $pays)
            ->update(['actif' => DB::raw('NOT actif')]); // Inversion atomique côté DB.
    }

    /**
     * Détecte l'opérateur et son logo depuis un numéro E.164 ou masqué
     * (ex : "+24177****56"). Retourne ['operateur' => ..., 'operateur_logo' => ...].
     */
    public function detectOperateur(string $numero, string $projectId): array
    {
        // Pour les numéros masqués "+24177****56", on supprime tout ce qui suit
        // le premier "*" afin de ne comparer que la partie connue du numéro.
        $knownPart = preg_replace('/\*.*$/', '', $numero);
        // On ne garde que les chiffres (retire "+", espaces, tirets…).
        $clean     = preg_replace('/[^\d]/', '', $knownPart);

        if (! $clean) {
            // Numéro vide ou entièrement masqué → détection impossible.
            return ['operateur' => null, 'operateur_logo' => null];
        }

        // On ne charge que les configs actives pour ce projet.
        $configs = TondoProjectConfig::where('project_id', $projectId)
            ->where('actif', true)
            ->get();

        foreach ($configs as $cfg) {
            // Normalise l'indicatif (retire tout sauf les chiffres).
            $indicatif = preg_replace('/[^\d]/', '', $cfg->indicatif ?? '');
            if (! $indicatif || ! str_starts_with($clean, $indicatif)) {
                continue;
            }
            // Partie locale après l'indicatif, sans le zéro initial
            // (ex : "24177123456" - "241" = "77123456").
            $localPart         = substr($clean, strlen($indicatif));
            // Le préfixe peut être stocké en format local avec zéro ("077")
            // alors que la partie locale après indicatif n'en a pas ("77").
            // On teste les deux formes pour couvrir les deux conventions.
            $localPartAvecZero = '0' . $localPart;
            foreach (($cfg->prefixes ?? []) as $prefix) {
                if (str_starts_with($localPart, $prefix) ||
                    str_starts_with($localPartAvecZero, $prefix)) {
                    return [
                        'operateur'      => $cfg->operateur,      // Ex : 'airtel'.
                        'operateur_logo' => $cfg->logo,           // URL du logo (pour l'UI).
                    ];
                }
            }
        }

        // Aucun opérateur détecté pour ce numéro.
        return ['operateur' => null, 'operateur_logo' => null];
    }

    /**
     * Supprime définitivement la configuration d'un opérateur pour un projet.
     *
     * À utiliser avec précaution : si des cagnottes en cours référencent
     * cet opérateur, les calculs de frais tomberont sur le fallback config/airtel.php.
     */
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
