<?php

namespace App\Services;

use App\Models\TondoCagnotte;
use Illuminate\Http\Client\ConnectionException;
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
 * Le décaissement Paynala est SYNCHRONE : `disburse()` répond immédiatement.
 * Un seul cas laisse un état intermédiaire — le timeout réseau, où l'issue est
 * inconnue : le payout reste alors `en_cours` et le solde n'est pas restauré.
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
     *  2. Appel Paynala, avec deux issues d'échec bien distinctes :
     *     – **refus explicite** (Paynala a répondu non) : le payout passe `echec`
     *       et le solde est RESTAURÉ. L'argent n'est pas parti, la cagnotte le
     *       récupère : le décrément ne tient que si le décaissement est validé.
     *     – **issue inconnue** (timeout, réseau) : on ne sait PAS si l'argent est
     *       parti. Recréditer permettrait un second décaissement du même montant,
     *       donc le solde n'est pas restauré ; le payout reste `en_cours` et
     *       demande une régularisation manuelle.
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
        } catch (ConnectionException $e) {
            // ISSUE INCONNUE — la requête n'a pas abouti, mais elle a pu être
            // reçue et traitée côté opérateur. Restaurer le solde ici ouvrirait
            // la porte à un second décaissement du même montant : on ne touche
            // à rien et on laisse le payout `en_cours` pour régularisation.
            DB::table(project_table('payout'))
                ->where('id', $payoutId)
                ->update([
                    'statut'     => 'en_cours',
                    'response'   => json_encode(['error' => $e->getMessage(), 'issue' => 'inconnue']),
                    'updated_at' => now(),
                ]);

            Log::critical("[{$source}] Paynala injoignable — issue inconnue, régularisation requise", [
                'cagnotte'        => $cagnotte->reference,
                'payout_id'       => $payoutId,
                'idempotency_key' => $idempotencyKey,
                'montant'         => $montant,
                'error'           => $e->getMessage(),
            ]);

            return [
                'ok' => false, 'montant' => $montant, 'payout_id' => $payoutId, 'trans_id' => $transId,
                'erreur' => "Paynala injoignable — l'issue du décaissement de {$montant} FCFA est inconnue, "
                    . 'le solde n\'a pas été restauré. À régulariser avant toute nouvelle tentative.',
            ];
        } catch (\RuntimeException $e) {
            // REFUS EXPLICITE — Paynala a répondu non : l'argent n'est pas parti.
            // La réservation de la phase 1 est compensée dans la même transaction
            // que le passage en `echec`, pour que le solde ne reste jamais amputé
            // d'un montant qui n'a pas quitté la cagnotte.
            DB::transaction(function () use ($payoutId, $cagnotte, $montant, $e) {
                DB::table(project_table('payout'))
                    ->where('id', $payoutId)
                    ->update([
                        'statut'     => 'echec',
                        'response'   => json_encode(['error' => $e->getMessage(), 'solde_restaure' => true]),
                        'updated_at' => now(),
                    ]);

                DB::table(project_table('cagnottes'))
                    ->where('id', $cagnotte->id)
                    ->update([
                        'montant_collecte' => DB::raw('montant_collecte + ' . $montant),
                        'updated_at'       => now(),
                    ]);
            });

            Log::warning("[{$source}] Décaissement refusé — solde restauré", [
                'cagnotte'        => $cagnotte->reference,
                'payout_id'       => $payoutId,
                'idempotency_key' => $idempotencyKey,
                'montant'         => $montant,
                'error'           => $e->getMessage(),
            ]);

            return [
                'ok' => false, 'montant' => $montant, 'payout_id' => $payoutId, 'trans_id' => $transId,
                'erreur' => 'Décaissement refusé (solde restauré) : ' . $e->getMessage(),
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
