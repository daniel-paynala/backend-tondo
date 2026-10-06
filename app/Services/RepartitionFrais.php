<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Répartition des frais d'une sortie d'argent entre plusieurs comptes.
 *
 * Un transfert devient plusieurs décaissements. Pour 100 000 FCFA à 3 % répartis
 * en Airtel 1 % / Paynala 0,5 % / Custom 1,5 % :
 *
 *   1 000 → Airtel · 500 → Paynala · 1 500 → Custom · 97 000 → bénéficiaire
 *
 * Les frais sont **retenus sur le montant** : la collecte est débitée de ce que
 * le client a saisi, le bénéficiaire reçoit le reste.
 *
 * ── Deux invariants, et ils ne se négocient pas ──────────────────────────────
 *
 * 1. **La somme rend toujours le montant.** `net + Σ parts == montant`, au
 *    franc. Le net est donc calculé par SOUSTRACTION, jamais par son propre
 *    pourcentage : trois arrondis indépendants ne retombent pas sur le total,
 *    et le franc manquant serait créé ou détruit à chaque transaction.
 *
 * 2. **Aucun compte configuré = aucune part.** La fonction s'active en
 *    remplissant le dashboard, pas en basculant un drapeau. Tant que la table
 *    est vide, le comportement est exactement celui d'avant.
 *
 * ── Le taux affiché est la somme des lignes ─────────────────────────────────
 *
 * Il n'y a pas de « taux du service » à maintenir à côté de la répartition :
 * ce serait deux sources pour une même information, et elles finiraient par se
 * contredire — un taux à 3 % avec des lignes qui font 3,5 %, et c'est le client
 * qui paie la différence. {@see tauxTotal()} fait foi.
 */
class RepartitionFrais
{
    /**
     * Parts à prélever sur [$montant] pour [$service].
     *
     * @param  'transfert'|'marchand' $service
     * @param  float|null $tauxImpose  Taux total à respecter, quand il est
     *        négocié hors répartition (taux propre à un marchand). Les parts
     *        sont alors mises à l'échelle proportionnellement : un partenaire à
     *        1 % au lieu de 3 % fait vivre les mêmes comptes, trois fois moins.
     *        Null = on applique les lignes telles quelles.
     *
     * @return array{
     *   net: int,
     *   frais_total: int,
     *   parts: array<int, array{compte_id: string, libelle: string, numero_tel: string, type_paynala: string, taux: float, montant: int}>
     * }
     */
    public function pour(string $service, string $projectId, int $montant, ?float $tauxImpose = null): array
    {
        $comptes = $this->comptes($service, $projectId);

        if ($comptes->isEmpty() || $montant <= 0) {
            return ['net' => $montant, 'frais_total' => 0, 'parts' => []];
        }

        // Mise à l'échelle d'un taux négocié. Sans somme de référence on ne
        // peut rien proportionner : on applique alors les lignes telles quelles.
        $sommeLignes = (float) $comptes->sum('taux');
        $facteur = ($tauxImpose !== null && $sommeLignes > 0)
            ? $tauxImpose / $sommeLignes
            : 1.0;

        $parts = [];
        $total = 0;

        foreach ($comptes as $compte) {
            $taux = (float) $compte->taux * $facteur;
            $part = (int) round($montant * $taux);

            // Une part nulle n'est pas une part : elle encombrerait le journal
            // de lignes à zéro qu'aucun décaissement ne réglera jamais.
            if ($part <= 0) {
                continue;
            }

            $parts[] = [
                'compte_id'    => $compte->id,
                'libelle'      => $compte->libelle,
                'numero_tel'   => $compte->numero_tel,
                'type_paynala' => $compte->type_paynala,
                'taux'         => $taux,
                'montant'      => $part,
            ];
            $total += $part;
        }

        // Garde-fou : des frais qui dépasseraient le montant videraient la
        // sortie de son sens et enverraient un net négatif à l'opérateur. Ça ne
        // devrait pas arriver — la borne par ligne et le contrôle de la somme
        // au dashboard l'interdisent — mais ici c'est l'argent du client.
        if ($total >= $montant) {
            throw new \RuntimeException(
                'Répartition des frais invalide : les parts (' . $total
                . ' FCFA) atteignent ou dépassent le montant (' . $montant . ' FCFA).'
            );
        }

        return [
            // Par soustraction : voir l'invariant 1.
            'net'         => $montant - $total,
            'frais_total' => $total,
            'parts'       => $parts,
        ];
    }

    /**
     * Taux total d'un service — la somme de ses lignes actives.
     *
     * C'est LE taux du service, celui qui s'affiche au client et dans les
     * conditions d'utilisation.
     */
    public function tauxTotal(string $service, string $projectId): float
    {
        return (float) $this->comptes($service, $projectId)->sum('taux');
    }

    /**
     * Comptes actifs d'un service, ordre stable.
     *
     * L'ordre importe pour la reproductibilité : deux calculs du même montant
     * doivent donner exactement les mêmes parts, y compris au franc d'arrondi.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function comptes(string $service, string $projectId): \Illuminate\Support\Collection
    {
        return DB::table(project_table('frais_comptes'))
            ->where('project_id', $projectId)
            ->where('service', $service)
            ->where('actif', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'libelle', 'numero_tel', 'type_paynala', 'taux']);
    }
}
