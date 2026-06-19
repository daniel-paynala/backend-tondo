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
     * Initialise le calculateur depuis un tableau de config ou depuis config('airtel').
     *
     * Les tranches sont triées par montant_min croissant afin que fee()
     * retourne toujours la première tranche applicable (la plus basse).
     *
     * @param array<string,mixed>|null $config  Si null, lu depuis config('airtel').
     */
    public function __construct(?array $config = null)
    {
        // Utilise la config passée en paramètre, ou la config globale 'airtel' par défaut.
        $config ??= config('airtel');

        $this->plafondParEnvoi   = (int) $config['plafond_par_envoi'];
        $this->plafondJournalier = (int) $config['plafond_journalier'];
        $this->tranches          = $config['tranches'] ?? [];

        // Tri croissant par montant_min pour garantir que fee() retourne
        // toujours la tranche la plus basse qui s'applique.
        usort($this->tranches, static function (array $a, array $b): int {
            $aMin = isset($a['montant_min']) ? (int) $a['montant_min'] : 0;
            $bMin = isset($b['montant_min']) ? (int) $b['montant_min'] : 0;
            return $aMin <=> $bMin;
        });
    }

    /**
     * Retourne le plafond journalier de décaissement en FCFA.
     *
     * Utilisé par les jobs de retrait pour répartir les payouts
     * sur plusieurs jours si le total dépasse ce seuil.
     *
     * @return int  Montant en FCFA.
     */
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
        $reste        = $cashCible; // Montant restant à décaisser au bénéficiaire.
        $nombreSplits = 0;          // Nombre d'envois excédant le plafond (splits forcés).

        // Tant que le reste dépasse le plafond par envoi, on génère un
        // envoi au plafond et on soustrait ce montant net du reste.
        while ($reste > $this->plafondParEnvoi) {
            $fee      = $this->fee($this->plafondParEnvoi);
            $envois[] = [
                'gross'        => $this->plafondParEnvoi + $fee, // Montant débité au cotisant (net + frais Airtel).
                'net'          => $this->plafondParEnvoi,         // Montant reçu par le bénéficiaire.
                'frais_airtel' => $fee,                           // Frais Airtel de cet envoi.
            ];
            $reste -= $this->plafondParEnvoi;
            $nombreSplits++;
        }

        // Dernier envoi (ou unique envoi si cashCible ≤ plafondParEnvoi).
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
            // Somme de tous les montants bruts (ce que Paynala débite en tout).
            'total_a_envoyer'    => array_sum(array_column($envois, 'gross')),
            // Somme de tous les frais Airtel (pour info / back-office).
            'total_frais_airtel' => array_sum(array_column($envois, 'frais_airtel')),
            // Doit être égal à $cashCible si tout se passe bien.
            'cash_livre'         => array_sum(array_column($envois, 'net')),
            'nombre_envois'      => count($envois),
            // 0 si un seul envoi suffit, N si le montant a été découpé.
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
            // Borne basse : absente OU net >= montant_min.
            $minOk = (! isset($t['montant_min'])) || $net >= (int) $t['montant_min'];
            // Borne haute : absente, null explicite OU net <= montant_max.
            $maxOk = (! isset($t['montant_max'])) || $t['montant_max'] === null
                || $net <= (int) $t['montant_max'];

            if ($minOk && $maxOk) {
                // Type 'pourcentage' : frais = round(net × valeur), ex. 0.03 × 5000 = 150 FCFA.
                // Type 'forfait'     : frais = valeur FCFA fixe, ex. 5000 FCFA.
                return $t['type'] === 'pourcentage'
                    ? (int) round((float) $t['valeur'] * $net)
                    : (int) $t['valeur'];
            }
        }
        // Aucune tranche configurée → pas de frais de retrait.
        return 0;
    }
}
