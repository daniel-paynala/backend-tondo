<?php

namespace App\Services;

use RuntimeException;

/**
 * Calculateur de frais de retrait pour les décaissements Mobile Money.
 *
 * Modèle A (validé 2026-05-12) : le cotisant absorbe tous les frais de retrait ;
 * le bénéficiaire reçoit exactement le cash annoncé.
 *
 * Grille tarifaire : tableau `tranches` libre, configurable par opérateur/pays
 * depuis le dashboard admin. Chaque tranche définit :
 *   - montant_max : gross max auquel cette tranche s'applique (null = sans limite)
 *   - type        : "pourcentage" (frais = ceil(gross * valeur)) |
 *                   "forfait"     (frais = valeur FCFA fixe)
 *   - valeur      : taux décimal (0.03 = 3 %) ou montant FCFA
 *
 * 0 tranche configurée → frais de retrait = 0.
 */
class AirtelFeesCalculator
{
    private int $plafondParEnvoi;
    private int $plafondJournalier;

    /** Tranches triées par montant_max croissant (null en dernier). */
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
            if ($a['montant_max'] === null) return 1;
            if ($b['montant_max'] === null) return -1;
            return (int) $a['montant_max'] <=> (int) $b['montant_max'];
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

        // Envoyer au plafond tant que le reste dépasse ce qu'un envoi max peut couvrir
        while ($reste > $cashMax) {
            $fee      = $this->fee($this->plafondParEnvoi);
            $envois[] = [
                'gross'         => $this->plafondParEnvoi,
                'net'           => $cashMax,
                'frais_airtel'  => $fee,
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

    /** Frais de retrait pour un gross donné (première tranche applicable). */
    private function fee(int $gross): int
    {
        foreach ($this->tranches as $t) {
            if ($t['montant_max'] === null || $gross <= (int) $t['montant_max']) {
                return $t['type'] === 'pourcentage'
                    ? (int) ceil((float) $t['valeur'] * $gross)
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
     * Évalue chaque tranche dans sa plage et retient le candidat le moins cher.
     */
    private function envoiFinal(int $cashLeft): array
    {
        $candidats = [];
        $prevMax   = 99; // gross minimum Mobile Money = 100 → prevMax = 99

        foreach ($this->tranches as $t) {
            $minGross = $prevMax + 1;
            $maxGross = $t['montant_max'] !== null
                ? min((int) $t['montant_max'], $this->plafondParEnvoi)
                : $this->plafondParEnvoi;
            $prevMax  = $t['montant_max'] ?? PHP_INT_MAX;

            if ($minGross > $maxGross) {
                continue;
            }

            if ($t['type'] === 'pourcentage') {
                $rate = (float) $t['valeur'];
                if ($rate >= 1.0) {
                    continue;
                }
                // Net max livrable par cette tranche à son propre plafond
                $maxNetTranche = $maxGross - (int) ceil($rate * $maxGross);
                if ($cashLeft > $maxNetTranche) {
                    continue;
                }
                $gross = (int) ceil($cashLeft / (1 - $rate));
                // Nudge until net >= cashLeft (arrondi au supérieur)
                while ($gross <= $maxGross
                    && ($gross - (int) ceil($rate * $gross)) < $cashLeft) {
                    $gross++;
                }
                $frais = (int) ceil($rate * $gross);
                if ($gross >= $minGross && $gross <= $maxGross && $gross - $frais >= $cashLeft) {
                    $candidats[] = ['gross' => $gross, 'net' => $gross - $frais, 'frais_airtel' => $frais];
                }
            } else {
                // Forfait : gross = max(cashLeft + forfait, minGross)
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
