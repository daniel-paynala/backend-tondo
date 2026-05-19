<?php

namespace App\Services;

use RuntimeException;

/**
 * Calculateur de frais de retrait pour les décaissements Mobile Money.
 *
 * Modèle A (validé 2026-05-12) : le cotisant absorbe tous les frais de retrait ;
 * le bénéficiaire reçoit exactement le cash annoncé.
 *
 * Chaque tranche définit une plage gross [montant_min, montant_max] :
 *   - montant_min : null = pas de borne basse (≥ 100 FCFA min Mobile Money)
 *   - montant_max : null = pas de borne haute
 *   - type "pourcentage" : frais = ceil(gross * valeur)
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
        $cashMax      = $this->cashParEnvoiMax();

        while ($reste > $cashMax) {
            $fee      = $this->fee($this->plafondParEnvoi);
            $envois[] = [
                'gross'        => $this->plafondParEnvoi,
                'net'          => $cashMax,
                'frais_airtel' => $fee,
            ];
            $reste -= $cashMax;
            $nombreSplits++;
        }

        if ($reste > 0) {
            $envois[] = $this->envoiFinal($reste);
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
     * Frais de retrait pour un gross donné.
     * Retourne le frais de la première tranche dont [montant_min, montant_max]
     * contient $gross. Retourne 0 si aucune tranche ne s'applique.
     */
    private function fee(int $gross): int
    {
        foreach ($this->tranches as $t) {
            $minOk = (! isset($t['montant_min'])) || $gross >= (int) $t['montant_min'];
            $maxOk = (! isset($t['montant_max'])) || $t['montant_max'] === null
                || $gross <= (int) $t['montant_max'];

            if ($minOk && $maxOk) {
                return $t['type'] === 'pourcentage'
                    ? (int) ceil(round((float) $t['valeur'] * $gross, 2))
                    : (int) $t['valeur'];
            }
        }
        return 0;
    }

    /** Cash net maximum livrable par un envoi saturé au plafond. */
    private function cashParEnvoiMax(): int
    {
        return $this->plafondParEnvoi - $this->fee($this->plafondParEnvoi);
    }

    /**
     * Trouve le gross minimum valide qui livre >= $cashLeft net.
     * Pour chaque tranche, calcule le candidat optimal dans [minGross, maxGross]
     * et retient le moins cher.
     */
    private function envoiFinal(int $cashLeft): array
    {
        $candidats = [];

        foreach ($this->tranches as $t) {
            $minGross = isset($t['montant_min']) && $t['montant_min'] !== null
                ? max((int) $t['montant_min'], 100)
                : 100;
            $maxGross = isset($t['montant_max']) && $t['montant_max'] !== null
                ? min((int) $t['montant_max'], $this->plafondParEnvoi)
                : $this->plafondParEnvoi;

            if ($minGross > $maxGross) {
                continue;
            }

            if ($t['type'] === 'pourcentage') {
                $rate = (float) $t['valeur'];
                if ($rate >= 1.0) {
                    continue;
                }
                $maxNetTranche = $maxGross - (int) ceil(round($rate * $maxGross, 2));
                if ($cashLeft > $maxNetTranche) {
                    continue;
                }
                $gross = (int) ceil($cashLeft / (1 - $rate));
                $gross = max($gross, $minGross);
                while ($gross <= $maxGross
                    && ($gross - (int) ceil(round($rate * $gross, 2))) < $cashLeft) {
                    $gross++;
                }
                $frais = (int) ceil(round($rate * $gross, 2));
                if ($gross >= $minGross && $gross <= $maxGross && $gross - $frais >= $cashLeft) {
                    $candidats[] = ['gross' => $gross, 'net' => $gross - $frais, 'frais_airtel' => $frais];
                }
            } else {
                $forfait = (int) $t['valeur'];
                $gross   = max($cashLeft + $forfait, $minGross);
                if ($gross <= $maxGross) {
                    $candidats[] = ['gross' => $gross, 'net' => $gross - $forfait, 'frais_airtel' => $forfait];
                }
            }
        }

        // Sans tranches : envoi direct, frais = 0
        if (empty($candidats) && empty($this->tranches)) {
            if ($cashLeft <= $this->plafondParEnvoi) {
                return ['gross' => $cashLeft, 'net' => $cashLeft, 'frais_airtel' => 0];
            }
        }

        if (empty($candidats)) {
            throw new RuntimeException(
                "Impossible de planifier un envoi pour {$cashLeft} FCFA avec la grille configurée."
            );
        }

        usort($candidats, fn ($a, $b) => $a['gross'] <=> $b['gross']);
        return $candidats[0];
    }
}
