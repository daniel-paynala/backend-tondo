<?php

namespace App\Services;

use App\Models\TondoCagnotte;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Rapatriement du solde d'une cagnotte vers son numéro de retrait.
 *
 * Utilisé par la suppression de compte (super admin) : on ne supprime jamais un
 * compte dont une cagnotte détient encore de l'argent — les fonds sont d'abord
 * renvoyés au numéro de retrait, immuable depuis la création.
 *
 * ⚠️ DUPLICATION ASSUMÉE — {@see \App\Console\Commands\TraiterReversementsAutoCagnottes}
 * implémente la même séquence en trois phases pour le cron quotidien de 18h.
 * Les deux chemins n'ont volontairement pas été fusionnés : unifier le code qui
 * décaisse réellement de l'argent mérite un changement dédié et testé, pas un
 * effet de bord. Toute correction ici doit être reportée là-bas, et inversement.
 *
 * Le décaissement Paynala est SYNCHRONE : `disburse()` répond immédiatement,
 * il n'y a donc pas d'état intermédiaire à gérer côté appelant.
 */
class ReversementService
{
    public function __construct(
        private readonly PaynalaPaymentService $paynala,
    ) {
    }

    /**
     * Reverse l'intégralité du solde de [$cagnotte] sur son numéro de retrait.
     *
     * Trois phases, identiques au cron :
     *  1. Réservation sous row-lock : insère le payout `initie` et décrémente le
     *     solde dans la même transaction — deux appels concurrents ne peuvent pas
     *     reverser deux fois.
     *  2. Appel Paynala. En cas d'échec, le payout passe `echec` et le solde
     *     n'est PAS restauré : la ligne reste comme trace d'un décaissement à
     *     instruire à la main (même politique que le cron).
     *  3. Confirmation `succes` et clôture de la cagnotte si demandée.
     *
     * @param  string $source        Trace écrite dans `payout.request` (ex : 'suppression_compte').
     * @param  string $prefixeIdem   Préfixe de la clé d'idempotence Paynala.
     * @param  string $prefixeTrans  Préfixe du trans_id interne.
     * @param  bool   $cloturer      Clôture la cagnotte après un reversement réussi.
     * @return array{ok: bool, montant: int, payout_id: ?string, trans_id: ?string, erreur: ?string}
     */
    public function reverserSolde(
        TondoCagnotte $cagnotte,
        string $source,
        string $prefixeIdem,
        string $prefixeTrans,
        bool   $cloturer = true,
    ): array {
        $montant    = (int) $cagnotte->montant_collecte;
        $numeroE164 = $cagnotte->numero_retrait;

        if ($montant <= 0) {
            return ['ok' => true, 'montant' => 0, 'payout_id' => null, 'trans_id' => null, 'erreur' => null];
        }

        if (! $numeroE164) {
            return [
                'ok' => false, 'montant' => $montant, 'payout_id' => null, 'trans_id' => null,
                'erreur' => 'Aucun numéro de retrait sur la cagnotte — reversement impossible.',
            ];
        }

        // Conversion E.164 +241XXXXXXXX → local 0XXXXXXXX attendu par l'API Airtel.
        $msisdnLocal = str_starts_with($numeroE164, '+241')
            ? '0' . substr($numeroE164, 4)
            : ltrim($numeroE164, '+');

        $nextNum        = DB::table(project_table('payout'))->count() + 1;
        $reference      = 'TONDODISBURSEMENT' . now()->getTimestampMs();
        $idempotencyKey = $prefixeIdem . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
        $payoutId       = (string) Str::uuid();
        $transId        = $prefixeTrans . strtoupper(Str::random(9));

        // Bénéficiaire du retrait — peut être un tiers (cagnotte créée pour un proche).
        $beneficiaireUserId = DB::table('users')->where('numero', $numeroE164)->value('id');

        // ── Phase 1 : réserver sous row-lock ─────────────────────────────────
        try {
            DB::transaction(function () use (
                $cagnotte, $montant, $payoutId, $transId,
                $idempotencyKey, $reference, $numeroE164, $beneficiaireUserId, $source
            ) {
                $solde = (int) DB::table(project_table('cagnottes'))
                    ->where('id', $cagnotte->id)
                    ->lockForUpdate()
                    ->value('montant_collecte');

                if ($solde < $montant || $solde <= 0) {
                    throw new \RuntimeException("Solde insuffisant ou nul : {$solde} FCFA.");
                }

                DB::table(project_table('payout'))->insert([
                    'id'            => $payoutId,
                    'project_id'    => $cagnotte->project_id,
                    'cagnotte_id'   => $cagnotte->id,
                    'user_id'       => $beneficiaireUserId,
                    'trans_id'      => $transId,
                    'operateur_id'  => null,
                    'numero_tel'    => $numeroE164,
                    'montant'       => $montant,
                    'statut'        => 'initie',
                    'request'       => json_encode([
                        'idempotency_key'    => $idempotencyKey,
                        'reference'          => $reference,
                        'cagnotte_reference' => $cagnotte->reference,
                        'montant'            => $montant,
                        'source'             => $source,
                    ]),
                    'date_creation' => now(),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                DB::table(project_table('cagnottes'))
                    ->where('id', $cagnotte->id)
                    ->update([
                        'montant_collecte' => DB::raw('montant_collecte - ' . $montant),
                        'updated_at'       => now(),
                    ]);
            });
        } catch (\Throwable $e) {
            Log::error("[{$source}] Échec réservation DB", [
                'cagnotte' => $cagnotte->reference,
                'error'    => $e->getMessage(),
            ]);

            return [
                'ok' => false, 'montant' => $montant, 'payout_id' => null, 'trans_id' => null,
                'erreur' => 'Réservation impossible : ' . $e->getMessage(),
            ];
        }

        // ── Phase 2 : décaissement Paynala ───────────────────────────────────
        $disburseType = $this->paynala->resolveDisburseType(
            msisdnLocal: $msisdnLocal,
            msisdnE164:  $numeroE164,
            userId:      $beneficiaireUserId,
        );

        try {
            $disburseData = $this->paynala->disburse(
                idempotencyKey: $idempotencyKey,
                amount:         $montant,
                msisdn:         $msisdnLocal,
                reference:      $reference,
                type:           $disburseType,
            );
        } catch (\RuntimeException $e) {
            DB::table(project_table('payout'))
                ->where('id', $payoutId)
                ->update([
                    'statut'     => 'echec',
                    'response'   => json_encode(['error' => $e->getMessage()]),
                    'updated_at' => now(),
                ]);

            // Solde déjà décrémenté et non restauré : une intervention manuelle
            // est nécessaire, exactement comme sur le chemin du cron.
            Log::critical("[{$source}] Paynala KO — intervention manuelle requise", [
                'cagnotte'        => $cagnotte->reference,
                'payout_id'       => $payoutId,
                'idempotency_key' => $idempotencyKey,
                'montant'         => $montant,
                'error'           => $e->getMessage(),
            ]);

            return [
                'ok' => false, 'montant' => $montant, 'payout_id' => $payoutId, 'trans_id' => $transId,
                'erreur' => 'Décaissement refusé : ' . $e->getMessage(),
            ];
        }

        // ── Phase 3 : confirmer + clôturer ───────────────────────────────────
        DB::transaction(function () use ($payoutId, $disburseData, $cagnotte, $cloturer) {
            DB::table(project_table('payout'))
                ->where('id', $payoutId)
                ->update([
                    'statut'       => 'succes',
                    'operateur_id' => $disburseData['airtel_money_id'] ?? null,
                    'response'     => json_encode($disburseData),
                    'updated_at'   => now(),
                ]);

            if ($cloturer) {
                DB::table(project_table('cagnottes'))
                    ->where('id', $cagnotte->id)
                    ->update(['statut' => 'cloturee', 'updated_at' => now()]);
            }
        });

        return [
            'ok' => true, 'montant' => $montant, 'payout_id' => $payoutId,
            'trans_id' => $transId, 'erreur' => null,
        ];
    }
}
