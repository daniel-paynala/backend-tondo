<?php

namespace App\Services;

use App\Models\TondoCagnotte;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Support\Registre;

/**
 * Rapatriement du solde d'une cagnotte vers son numéro de retrait.
 *
 * Utilisé par la suppression de compte (super admin) : on ne supprime jamais un
 * compte dont une cagnotte détient encore de l'argent — les fonds sont d'abord
 * renvoyés au numéro de retrait, immuable depuis la création.
 *
 * Point d'entrée UNIQUE du décaissement d'une cagnotte : la suppression de compte
 * et le cron quotidien de 18h ({@see \App\Console\Commands\TraiterReversementsAutoCagnottes})
 * passent tous deux par ici. Toute correction profite donc aux deux chemins.
 *
 * Le décaissement Paynala est SYNCHRONE : `disburse()` répond immédiatement.
 *
 * **Dans le doute, l'argent ne revient pas** (décision de Daniel, 2026-10-06).
 * Le solde n'est restauré que sur un refus AFFIRMÉ de l'opérateur
 * ({@see DecaissementRefuse}). Toute autre issue — coupure réseau, 5xx de
 * passerelle, réponse illisible — laisse le payout `en_cours` et le solde
 * amputé : la ligne remonte dans les réconciliations à traiter, et le cas se
 * règle avec l'opérateur. Même règle que {@see SortieArgent}, pour que le cron
 * et l'API ne décident pas différemment du même doute.
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
     *     – **refus affirmé** ({@see DecaissementRefuse}) : le payout passe
     *       `echec` et le solde est RESTAURÉ. L'argent n'est pas parti, la
     *       cagnotte le récupère : le décrément ne tient que si le décaissement
     *       est validé.
     *     – **issue inconnue** (tout le reste) : on ne sait PAS si l'argent est
     *       parti. Recréditer permettrait un second décaissement du même
     *       montant, donc le solde n'est pas restauré ; le payout reste
     *       `en_cours` et remonte dans les réconciliations à traiter.
     *  3. Confirmation `succes` et clôture de la cagnotte si demandée.
     *
     * @param  string $source        Trace écrite dans `payout.request` (ex : 'suppression_compte').
     * @param  string $cleTrans      Clé du registre pour le préfixe du trans_id
     *                               (ex : 'payout_auto'). Une CLÉ et non un
     *                               préfixe : le préfixe ne doit s'écrire nulle
     *                               part ailleurs que dans le registre.
     * @param  bool   $cloturer      Clôture la cagnotte après un reversement réussi.
     * @param  array<string, mixed> $trace Champs supplémentaires fusionnés dans
     *                                     `payout.request` (ex : le mode du cron).
     * @return array{ok: bool, montant: int, payout_id: ?string, trans_id: ?string, idempotency_key: ?string, erreur: ?string}
     */
    public function reverserSolde(
        TondoCagnotte $cagnotte,
        string $source,
        string $cleTrans,
        bool   $cloturer = true,
        array  $trace = [],
    ): array {
        $montant    = (int) $cagnotte->montant_collecte;
        $numeroE164 = $cagnotte->numero_retrait;

        if ($montant <= 0) {
            return ['ok' => true, 'montant' => 0, 'payout_id' => null, 'trans_id' => null, 'idempotency_key' => null, 'erreur' => null];
        }

        if (! $numeroE164) {
            return [
                'ok' => false, 'montant' => $montant, 'payout_id' => null, 'trans_id' => null,
                'idempotency_key' => null,
                'erreur' => 'Aucun numéro de retrait sur la cagnotte — transfert impossible.',
            ];
        }

        // Conversion E.164 +241XXXXXXXX → local 0XXXXXXXX attendu par l'API Airtel.
        $msisdnLocal = str_starts_with($numeroE164, '+241')
            ? '0' . substr($numeroE164, 4)
            : ltrim($numeroE164, '+');

        $reference = 'TONJIDISBURSEMENT' . now()->getTimestampMs();
        $payoutId  = (string) Str::uuid();
        $transId   = Registre::nouvelleReference($cleTrans);

        // Clé d'idempotence = la référence de la transaction elle-même.
        //
        // Elle était dérivée d'un COUNT(*) + 1 : deux transferts simultanés
        // produisaient la même clé, et surtout la recette repartait de 1 alors
        // qu'elle parle au MÊME Paynala que la production, faute
        // d'environnement de test chez eux. D'où le refus « Transaction
        // Ambiguous » : la clé avait déjà servi, pour d'autres montants.
        // Le trans_id est unique en base, il l'est donc aussi chez Paynala.
        $idempotencyKey = $transId;

        // Bénéficiaire du retrait — peut être un tiers (cagnotte créée pour un proche).
        $beneficiaireUserId = DB::table('users')->where('numero', $numeroE164)->value('id');

        // Même barème que les sorties manuelles, par le même service : le
        // reversement automatique n'est pas un cas à part, il emprunte
        // seulement une autre porte.
        $frais = app(FraisSortie::class)->pour($cagnotte, $montant);

        // ── Phase 1 : réserver sous row-lock ─────────────────────────────────
        try {
            DB::transaction(function () use (
                $cagnotte, $montant, $payoutId, $transId, $idempotencyKey,
                $reference, $numeroE164, $beneficiaireUserId, $source, $trace, $frais
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
                    // Ce que le barème Tonji prévoyait pour cette sortie.
                    // Indicatif : le montant envoyé n'en est pas diminué, c'est
                    // Paynala qui prélève. Mais c'est cette trace qui dit si une
                    // gratuité a été consommée — la règle du mois s'y réfère.
                    'frais_attendus' => $frais['frais'],

                    'request'       => json_encode(array_merge([
                        'idempotency_key'    => $idempotencyKey,
                        'reference'          => $reference,
                        'cagnotte_reference' => $cagnotte->reference,
                        'montant'            => $montant,
                        'source'             => $source,
                    ], $trace)),
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
                'idempotency_key' => $idempotencyKey,
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
        } catch (DecaissementRefuse $e) {
            // REFUS AFFIRMÉ — Paynala a répondu non : l'argent n'est pas parti.
            // La réservation de la phase 1 est compensée dans la même
            // transaction que le passage en `echec`, pour que le solde ne reste
            // jamais amputé d'un montant qui n'a pas quitté la cagnotte.
            //
            // Ce `catch` vient AVANT celui des autres erreurs : c'est la seule
            // exception qui autorise à recréditer.
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
                'idempotency_key' => $idempotencyKey,
                'erreur' => 'Décaissement refusé (solde restauré) : ' . $e->getMessage(),
            ];
        } catch (ConnectionException | \RuntimeException $e) {
            // ISSUE INCONNUE — tout ce qui n'est pas un refus affirmé. La
            // demande a pu être reçue et traitée côté opérateur. Restaurer le
            // solde ici ouvrirait la porte à un second décaissement du même
            // montant : on ne touche à rien, le payout reste `en_cours` et
            // remonte dans les réconciliations à traiter.
            DB::table(project_table('payout'))
                ->where('id', $payoutId)
                ->update([
                    'statut'     => 'en_cours',
                    'response'   => json_encode(['error' => $e->getMessage(), 'issue' => 'inconnue']),
                    'updated_at' => now(),
                ]);

            Log::critical("[{$source}] Issue inconnue — solde non restauré, régularisation requise", [
                'cagnotte'        => $cagnotte->reference,
                'payout_id'       => $payoutId,
                'idempotency_key' => $idempotencyKey,
                'montant'         => $montant,
                'error'           => $e->getMessage(),
            ]);

            return [
                'ok' => false, 'montant' => $montant, 'payout_id' => $payoutId, 'trans_id' => $transId,
                'idempotency_key' => $idempotencyKey,
                'erreur' => "L'issue du décaissement de {$montant} FCFA est inconnue, "
                    . 'le solde n\'a pas été restauré. À régulariser avec l\'opérateur '
                    . 'avant toute nouvelle tentative.',
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
            'trans_id' => $transId, 'idempotency_key' => $idempotencyKey, 'erreur' => null,
        ];
    }
}
