<?php

namespace App\Services;

use App\Support\Registre;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cotiser à une collecte en puisant dans une autre — aujourd'hui, son solde.
 *
 * ── Ce que c'est, et ce que ce n'est pas ────────────────────────────────────
 *
 * C'est **une cotisation faite par une collecte** (formulation de Daniel) :
 * l'argent change de contenant sans jamais sortir de Tonji. Il n'y a donc NI
 * `payin` NI `payout` — rien n'est entré, rien n'est sorti. En fabriquer un
 * polluerait la réconciliation, qui compare nos payins à ce que Paynala a
 * réellement reçu, et nos payouts à ce qu'il a réellement décaissé.
 *
 * Un débit, un crédit, une ligne de `paiements` qui dit d'où vient l'argent.
 * La somme des soldes ne bouge pas : elle continue d'égaler ce que Paynala
 * détient pour nous.
 *
 * ── Pourquoi c'est gratuit ──────────────────────────────────────────────────
 *
 * Aucun opérateur n'est appelé, donc personne ne prélève. Ce n'est pas une
 * faveur commerciale, c'est l'absence de coût.
 */
class CotisationInterne
{
    /** Montant minimum, aligné sur celui d'une cotisation Mobile Money. */
    public const MINIMUM = 100;

    /**
     * Déplace [$montant] de [$source] vers la collecte [$destination].
     *
     * @return array{trans_id:string, solde_source:int}
     *
     * @throws RuntimeException message destiné à l'utilisateur.
     */
    public function executer(object $source, object $destination, int $montant, ?string $commentaire = null): array
    {
        if ($montant < self::MINIMUM) {
            throw new RuntimeException('Montant minimum : ' . self::MINIMUM . ' FCFA.');
        }
        if ($source->id === $destination->id) {
            throw new RuntimeException('Impossible de cotiser à son propre solde.');
        }

        $transId = Registre::nouvelleReference('paiement_interne');

        return DB::transaction(function () use ($source, $destination, $montant, $commentaire, $transId) {
            $cagnottes = project_table('cagnottes');

            // DÉBIT CONDITIONNEL — la garde est dans le WHERE, pas dans un
            // `if` qui précède. Deux cotisations simultanées passeraient toutes
            // deux un contrôle préalable et videraient le solde deux fois ;
            // ici, la seconde n'affecte aucune ligne et s'arrête.
            $debite = DB::table($cagnottes)
                ->where('id', $source->id)
                ->where('montant_collecte', '>=', $montant)
                ->update([
                    'montant_collecte' => DB::raw('montant_collecte - ' . $montant),
                    'updated_at'       => now(),
                ]);

            if ($debite === 0) {
                throw new RuntimeException('Solde insuffisant.');
            }

            // CRÉDIT — le cumul monte avec le solde, dans la même instruction :
            // l'argent reçu est une cotisation comme une autre pour la collecte
            // qui l'encaisse, et il compte pour sa gratuité de reversement.
            DB::table($cagnottes)
                ->where('id', $destination->id)
                ->update([
                    'montant_collecte'  => DB::raw('montant_collecte + ' . $montant),
                    'cumul_cotisations' => DB::raw('cumul_cotisations + ' . $montant),
                    'updated_at'        => now(),
                ]);

            // Le membre, s'il en est un. Une collecte ouverte accepte un
            // cotisant qui n'est pas inscrit : la ligne de paiement n'exige
            // pas de participant.
            $participant = DB::table(project_table('participants'))
                ->where('cagnotte_id', $destination->id)
                ->where('user_id', $source->user_id)
                ->first();

            if ($participant) {
                DB::table(project_table('participants'))
                    ->where('id', $participant->id)
                    ->update([
                        'statut_paiement'       => 'paye',
                        'montant_paye'          => DB::raw('montant_paye + ' . $montant),
                        'date_dernier_paiement' => now(),
                    ]);
            }

            DB::table(project_table('paiements'))->insert([
                'id'             => (string) Str::uuid(),
                'project_id'     => $destination->project_id,
                'cagnotte_id'    => $destination->id,
                'participant_id' => $participant?->id,
                'user_id'        => $source->user_id,
                'canal'          => 'app',
                'commentaire'    => $commentaire,
                'trans_id'       => $transId,
                // La provenance : c'est elle qui distingue cette ligne d'une
                // cotisation Mobile Money, et qui permet au relevé de dire
                // « depuis mon solde ».
                'cagnotte_source_id' => $source->id,
                'montant'        => $montant,
                'date'           => now(),
                'created_at'     => now(),
            ]);

            $solde = (int) DB::table($cagnottes)
                ->where('id', $source->id)
                ->value('montant_collecte');

            return ['trans_id' => $transId, 'solde_source' => $solde];
        });
    }
}
