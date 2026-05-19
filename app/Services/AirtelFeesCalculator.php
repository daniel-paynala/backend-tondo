<?php

namespace App\Services;

use RuntimeException;

/**
 * Calculateur de frais Airtel Money pour les décaissements vers le bénéficiaire.
 *
 * Modèle A (validé 2026-05-12 par Daniel) : le COTISANT absorbe les frais de
 * retrait Airtel. Le bénéficiaire reçoit exactement le cash annoncé. Pour ça
 * on doit savoir combien envoyer au wallet pour qu'après retrait, il reste
 * exactement la cible.
 *
 * Grille tarifaire : lue depuis `config/airtel.php` (et à terme une table
 * éditable depuis le dashboard admin). Aucun taux codé en dur ici.
 *  - Tranche 1 : envoi dans [100 ; seuil_tranche] → frais = taux_pourcentage
 *  - Tranche 2 : envoi dans [seuil_tranche+1 ; plafond] → frais = forfait
 *
 * Stratégie : on sature au plafond tant qu'il reste plus que le cash livrable
 * par un envoi saturé, puis on régularise avec un dernier envoi calé sur la
 * tranche la moins chère.
 */
class AirtelFeesCalculator
{
    private int $plafondParEnvoi;
    private int $plafondJournalier;
    private int $seuilTranche;       // gross max de la tranche 1 (3 %)
    private float $tauxPourcentage;  // tranche 1
    private int $forfait;            // tranche 2

    /**
     * @param array<string,mixed>|null $config taux explicites (tests) ;
     *        si null, lus depuis `config('airtel')`.
     */
    public function __construct(?array $config = null)
    {
        $config ??= config('airtel');

        $this->plafondParEnvoi = (int) $config['plafond_par_envoi'];
        $this->plafondJournalier = (int) $config['plafond_journalier'];
        $this->seuilTranche = (int) $config['retrait']['seuil_tranche'];
        $this->tauxPourcentage = (float) $config['retrait']['taux_pourcentage'];
        $this->forfait = (int) $config['retrait']['forfait'];
    }

    /** Cash net livré par un envoi saturé (gross = plafond, frais = forfait). */
    private function cashParEnvoiMax(): int
    {
        return $this->plafondParEnvoi - $this->forfait;
    }

    /**
     * Cash net maximum livrable en tranche 1. À `gross = seuilTranche` le
     * frais 3 % est arrondi au supérieur ; le net dispo est donc
     * `seuilTranche - ceil(taux * seuilTranche)`.
     */
    private function trancheUnMaxNet(): int
    {
        return $this->seuilTranche - (int) ceil($this->tauxPourcentage * $this->seuilTranche);
    }

    public function plafondJournalier(): int
    {
        return $this->plafondJournalier;
    }

    /**
     * Calcule la séquence optimale d'envois pour livrer exactement
     * `$cashCible` FCFA en cash au bénéficiaire.
     *
     * Retour :
     *  - envois[]            : détail (gross, net, frais_airtel) par envoi
     *  - total_a_envoyer     : somme des gross (débité du wallet émetteur Paynala)
     *  - total_frais_airtel  : somme des frais Airtel
     *  - cash_livre          : somme des net (>= cashCible, exact en pratique)
     *  - nombre_envois       : nb total d'envois Mobile Money
     *  - nombre_splits       : nb d'envois saturés au plafond
     *
     * @return array<string,mixed>
     * @throws RuntimeException si le cash cible est hors bornes valides.
     */
    public function plan(int $cashCible): array
    {
        if ($cashCible < 100) {
            throw new RuntimeException("Cash cible doit être >= 100 FCFA (minimum Airtel).");
        }

        $envois = [];
        $reste = $cashCible;
        $nombreSplits = 0;
        $cashParEnvoiMax = $this->cashParEnvoiMax();

        while ($reste > $cashParEnvoiMax) {
            $envois[] = [
                'gross' => $this->plafondParEnvoi,
                'net' => $cashParEnvoiMax,
                'frais_airtel' => $this->forfait,
            ];
            $reste -= $cashParEnvoiMax;
            $nombreSplits++;
        }

        if ($reste > 0) {
            $envois[] = $this->envoiFinal($reste);
        }

        $totalAEnvoyer = array_sum(array_column($envois, 'gross'));
        $totalFraisAirtel = array_sum(array_column($envois, 'frais_airtel'));
        $cashLivre = array_sum(array_column($envois, 'net'));

        return [
            'envois' => $envois,
            'total_a_envoyer' => $totalAEnvoyer,
            'total_frais_airtel' => $totalFraisAirtel,
            'cash_livre' => $cashLivre,
            'nombre_envois' => count($envois),
            'nombre_splits' => $nombreSplits,
        ];
    }

    /**
     * Trouve le plus petit `gross` valide qui livre au moins `$cashLeft`
     * en cash net. Évalue les deux tranches et garde la moins chère.
     *
     * @return array<string,int>
     */
    private function envoiFinal(int $cashLeft): array
    {
        $candidats = [];

        if ($cashLeft <= $this->trancheUnMaxNet()) {
            $gross = (int) ceil($cashLeft / (1 - $this->tauxPourcentage));
            while ($gross - (int) ceil($this->tauxPourcentage * $gross) < $cashLeft
                   && $gross <= $this->seuilTranche) {
                $gross++;
            }
            $frais = (int) ceil($this->tauxPourcentage * $gross);
            if ($gross >= 100 && $gross <= $this->seuilTranche
                && $gross - $frais >= $cashLeft) {
                $candidats[] = [
                    'gross' => $gross,
                    'net' => $gross - $frais,
                    'frais_airtel' => $frais,
                ];
            }
        }

        // Tranche 2 (forfait) : gross dans [seuil+1 ; plafond]. On prend
        // max(borne inf tranche 2, cashLeft + forfait) — ça gère proprement
        // la bascule où la tranche 1 est saturée : on entre forcément en
        // tranche 2 avec un léger overshoot (≤ 1 FCFA).
        $grossFlat = max($this->seuilTranche + 1, $cashLeft + $this->forfait);
        if ($grossFlat <= $this->plafondParEnvoi) {
            $candidats[] = [
                'gross' => $grossFlat,
                'net' => $grossFlat - $this->forfait,
                'frais_airtel' => $this->forfait,
            ];
        }

        if (empty($candidats)) {
            throw new RuntimeException(
                "Impossible de planifier un envoi pour {$cashLeft} FCFA (hors tranches Airtel)."
            );
        }

        usort($candidats, fn ($a, $b) => $a['gross'] <=> $b['gross']);
        return $candidats[0];
    }
}
