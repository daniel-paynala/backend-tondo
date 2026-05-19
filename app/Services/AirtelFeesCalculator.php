<?php

namespace App\Services;

use RuntimeException;

/**
 * Calculateur de frais de retrait pour les décaissements Mobile Money.
 *
 * Modèle A (validé 2026-05-12) : le cotisant absorbe tous les frais de retrait ;
 * le bénéficiaire reçoit exactement le cash annoncé.
 *
 * Chaque tranche définit une plage NET [montant_min, montant_max] :
 *   - montant_min : null = pas de borne basse (≥ 100 FCFA min Mobile Money)
 *   - montant_max : null = pas de borne haute
 *   - type "pourcentage" : frais = round(net * valeur)   ← appliqué sur le NET
 *   - type "forfait"     : frais = valeur FCFA fixe
 *
 * 0 tranche configurée → frais de retrait = 0.
 */
class AirtelFeesCalculator
{
    private int $plafondParEnvoi;
    private int $plafondJournalier;

    /** Tranches triées par montant_min croissant. */
    private array $tranches;

    /**
     * @param array<string,mixed>|null $config  Si null, lu depuis config('airtel').
     */
    public function __construct(?array $config = null)
    {
        $config ??= config('airtel');

        $this->plafondParEnvoi   = (int) $config['plafond_par_envoi'];
        $this->plafondJournalier = (int) $config['plafond_journalier'];
        $this->tranches          = $config['tranches'] ?? [];

        usort($this->tranches, static function (array $a, array $b): int {
            $aMin = isset($a['montant_min']) ? (int) $a['montant_min'] : 0;
            $bMin = isset($b['montant_min']) ? (int) $b['montant_min'] : 0;
            return $aMin <=> $bMin;
        });
    }

    public function plafondJournalier(): int
    {
        return $this->plafondJournalier;
    }

    /**
     * Calcule la séquence optimale d'envois pour livrer exactement
     * `$cashCible` FCFA net au bénéficiaire.
     *
     * @return array{envois:list<array>, total_a_envoyer:int, total_frais_airtel:int,
     *               cash_livre:int, nombre_envois:int, nombre_splits:int}
     */
    public function plan(int $cashCible): array
    {
        if ($cashCible < 100) {
            throw new RuntimeException("Cash cible doit être >= 100 FCFA (minimum Mobile Money).");
        }

        $envois       = [];
        $reste        = $cashCible;
        $nombreSplits = 0;

        while ($reste > $this->plafondParEnvoi) {
            $fee      = $this->fee($this->plafondParEnvoi);
            $envois[] = [
                'gross'        => $this->plafondParEnvoi + $fee,
                'net'          => $this->plafondParEnvoi,
                'frais_airtel' => $fee,
            ];
            $reste -= $this->plafondParEnvoi;
            $nombreSplits++;
        }

        if ($reste > 0) {
            $fee      = $this->fee($reste);
            $envois[] = [
                'gross'        => $reste + $fee,
                'net'          => $reste,
                'frais_airtel' => $fee,
            ];
        }

        return [
            'envois'             => $envois,
            'total_a_envoyer'    => array_sum(array_column($envois, 'gross')),
            'total_frais_airtel' => array_sum(array_column($envois, 'frais_airtel')),
            'cash_livre'         => array_sum(array_column($envois, 'net')),
            'nombre_envois'      => count($envois),
            'nombre_splits'      => $nombreSplits,
        ];
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Frais de retrait pour un net donné — arrondi par défaut (round).
     * Retourne le frais de la première tranche dont [montant_min, montant_max]
     * contient $net. Retourne 0 si aucune tranche ne s'applique.
     */
    private function fee(int $net): int
    {
        foreach ($this->tranches as $t) {
            $minOk = (! isset($t['montant_min'])) || $net >= (int) $t['montant_min'];
            $maxOk = (! isset($t['montant_max'])) || $t['montant_max'] === null
                || $net <= (int) $t['montant_max'];

            if ($minOk && $maxOk) {
                return $t['type'] === 'pourcentage'
                    ? (int) round((float) $t['valeur'] * $net)
                    : (int) $t['valeur'];
            }
        }
        return 0;
    }
}
