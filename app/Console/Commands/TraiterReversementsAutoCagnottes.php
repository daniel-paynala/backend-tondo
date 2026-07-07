<?php

namespace App\Console\Commands;

use App\Mail\DisbursementFailedMail;
use App\Models\TondoCagnotte;
use App\Services\OneSignalService;
use App\Services\PaynalaPaymentService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Déclenche les reversements automatiques des cotisations ouvertes.
 *
 * Planification : quotidienne à 18h00 (Africa/Libreville) dans routes/console.php.
 *
 * Quatre modes de déclenchement (priorité décroissante) :
 *  1. Date limite atteinte    — date_fin <= aujourd'hui
 *  2. Montant cible atteint   — montant_collecte >= montant_cible
 *  3. Fréquence libre         — reversement_auto_frequence_mois : tous les N mois
 *     depuis la dernière opération réussie (ou depuis la création si aucune).
 *  4. Systématique quotidien  — pas d'échéance configurée (ni date_fin, ni
 *     montant_cible, ni fréquence_mois) : si solde > 0, on reverse tout en fin
 *     de journée et la cagnotte reste active.
 *
 * Après succès :
 *  - Mode date / montant → cagnotte clôturée automatiquement (statut = cloturee).
 *  - Mode libre / quotidien → cagnotte reste active ; le prochain cycle reprend.
 *
 * En cas d'échec Paynala : log critique + mail admins, solde non restauré
 * (même politique que TraiterRetraitsTontines).
 */
class TraiterReversementsAutoCagnottes extends Command
{
    protected $signature   = 'cotisations:reversements-auto {--dry-run : Simule sans effectuer de transfert ni modifier la base}';
    protected $description = 'Déclenche les reversements automatiques des cotisations ouvertes.';

    /**
     * Point d'entrée de la commande.
     *
     * Sélectionne les cagnottes ouvertes éligibles et déclenche le reversement
     * selon le mode déterminé par `determinerMode()`.
     *
     * @param  PaynalaPaymentService $paynala Service de décaissement Mobile Money.
     * @param  OneSignalService      $notif   Service de notifications push.
     * @return int                            Code de retour (self::SUCCESS).
     */
    public function handle(
        PaynalaPaymentService $paynala,
        OneSignalService      $notif,
    ): int {
        $isDryRun = (bool) $this->option('dry-run');
        // Heure locale Gabon pour éviter un décalage de date lié à UTC.
        $today    = now()->timezone('Africa/Libreville')->toDateString();

        $this->info("[{$today}] Reversements auto cotisations" . ($isDryRun ? ' (dry-run)' : '') . ' …');

        // Critères d'éligibilité : cagnotte ouverte, auto-reversement activé,
        // solde positif, et numéro de retrait renseigné.
        $cagnottes = TondoCagnotte::where('type', 'cagnotte_ouverte')
            ->where('reversement_auto', true)
            ->whereIn('statut', ['active', 'en_cours'])
            ->where('montant_collecte', '>', 0)
            ->whereNotNull('numero_retrait')
            ->get();

        $this->line("  {$cagnottes->count()} cotisation(s) éligible(s) trouvée(s).");

        $traites = 0;
        $ignores = 0;

        foreach ($cagnottes as $cagnotte) {
            $mode = $this->determinerMode($cagnotte, $today);

            if (! $mode) {
                $ignores++;
                continue;
            }

            $this->line("  → [{$cagnotte->reference}] « {$cagnotte->titre} » — mode : {$mode}");

            if ($isDryRun) {
                $this->info('    [dry-run] Reversement non effectué.');
                $traites++;
                continue;
            }

            $ok = $this->traiter($cagnotte, $mode, $paynala, $notif);
            if ($ok) {
                $traites++;
            } else {
                $ignores++;
            }
        }

        $this->info("Terminé — {$traites} reversement(s) effectué(s), {$ignores} ignoré(s).");

        return self::SUCCESS;
    }

    // ── Logique de déclenchement ──────────────────────────────────────────────

    /**
     * Retourne le mode de déclenchement applicable pour une cagnotte, ou null si
     * aucune condition n'est remplie aujourd'hui.
     *
     * Priorité décroissante :
     *  1. 'date'          — date_fin atteinte ou dépassée.
     *  2. 'montant_cible' — solde >= montant_cible.
     *  3. 'libre'         — N mois écoulés depuis le dernier reversement réussi.
     *  4. 'quotidien'     — aucune échéance configurée : reverse le solde chaque jour.
     *
     * @param  TondoCagnotte $cagnotte Cagnotte à évaluer.
     * @param  string        $today   Date du jour au format 'Y-m-d' (Africa/Libreville).
     * @return string|null            Mode de déclenchement ou null si pas encore le moment.
     */
    private function determinerMode(TondoCagnotte $cagnotte, string $today): ?string
    {
        // Priorité 1 : date limite atteinte ou dépassée.
        if ($cagnotte->date_fin && $cagnotte->date_fin->toDateString() <= $today) {
            return 'date';
        }

        // Priorité 2 : montant cible atteint ou dépassé (collecte suffisante).
        if ($cagnotte->montant_cible && (int) $cagnotte->montant_collecte >= (int) $cagnotte->montant_cible) {
            return 'montant_cible';
        }

        // Priorité 3 : fréquence libre (tous les N mois depuis le dernier payout réussi).
        if ($cagnotte->reversement_auto_frequence_mois) {
            // Récupérer la date du dernier reversement réussi pour calculer le suivant.
            $dernierPayout = DB::table(project_table('payout'))
                ->where('cagnotte_id', $cagnotte->id)
                ->where('statut', 'succes')
                ->max('date_creation');

            // Si jamais de reversement, on part de la date de création de la cagnotte.
            $reference = $dernierPayout
                ? Carbon::parse($dernierPayout)->timezone('Africa/Libreville')
                : $cagnotte->date_creation->timezone('Africa/Libreville');

            $prochainDeclenchement = $reference->copy()
                ->addMonths((int) $cagnotte->reversement_auto_frequence_mois);

            if ($prochainDeclenchement->toDateString() <= $today) {
                return 'libre';
            }
        }

        // Priorité 4 : pas d'échéance configurée → reverse quotidiennement si solde > 0.
        if (! $cagnotte->date_fin && ! $cagnotte->montant_cible && ! $cagnotte->reversement_auto_frequence_mois) {
            return 'quotidien';
        }

        return null;
    }

    // ── Exécution du reversement ──────────────────────────────────────────────

    /**
     * Exécute le reversement pour une cagnotte éligible en trois phases atomiques.
     *
     * Phase 1 — Réservation (transaction DB avec row-lock) :
     *   Vérifie le solde et insère la ligne payout + décrémente montant_collecte.
     *
     * Phase 2 — Appel Paynala :
     *   Envoie le virement Mobile Money. En cas d'échec, marque le payout 'echec'
     *   et alerte les admins. Le solde n'est PAS restauré automatiquement.
     *
     * Phase 3 — Confirmation (transaction DB) :
     *   Met le payout à 'succes' et clôture la cagnotte si le mode le demande.
     *
     * @param  TondoCagnotte         $cagnotte Cagnotte à reverser.
     * @param  string                $mode     Mode déterminé par determinerMode().
     * @param  PaynalaPaymentService $paynala  Service de décaissement.
     * @param  OneSignalService      $notif    Service de notifications push.
     * @return bool                            True si le reversement a réussi, false sinon.
     */
    private function traiter(
        TondoCagnotte         $cagnotte,
        string                $mode,
        PaynalaPaymentService $paynala,
        OneSignalService      $notif,
    ): bool {
        // On reverse l'intégralité du solde collecté.
        $montant    = (int) $cagnotte->montant_collecte;
        $numeroE164 = $cagnotte->numero_retrait;
        // Convertir E.164 +241XXXXXXXX → local 0XXXXXXXX pour l'API Airtel.
        $msisdnLocal = str_starts_with($numeroE164, '+241')
            ? '0' . substr($numeroE164, 4)
            : ltrim($numeroE164, '+');

        // Générer les identifiants de la transaction.
        $nextNum        = DB::table(project_table('payout'))->count() + 1;
        $reference      = 'TONDODISBURSEMENT' . now()->getTimestampMs();
        $idempotencyKey = 'TONDO-AUTO-' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
        $payoutId       = (string) Str::uuid();
        $transId        = 'TONDOAUTO' . strtoupper(Str::random(9));

        // Résoudre l'user_id du bénéficiaire pour la notification push (optionnel).
        $beneficiaireUserId = DB::table('users')
            ->where('numero', $numeroE164)
            ->value('id');

        // ── Phase 1 : réserver sous row-lock ─────────────────────────────────
        try {
            DB::transaction(function () use (
                $cagnotte, $montant, $payoutId, $transId,
                $idempotencyKey, $reference, $numeroE164, $beneficiaireUserId, $mode
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
                        'mode'               => $mode,
                        'source'             => 'cron_reversement_auto',
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
            Log::error('[reversements-auto] Échec réservation DB', [
                'cagnotte' => $cagnotte->reference,
                'error'    => $e->getMessage(),
            ]);
            $this->error("    Échec réservation : {$e->getMessage()}");

            return false;
        }

        // ── Phase 2 : appel Paynala ───────────────────────────────────────────
        $disburseType = $paynala->resolveDisburseType(
            msisdnLocal: $msisdnLocal,
            msisdnE164:  $numeroE164,
            userId:      $beneficiaireUserId,
        );

        try {
            $disburseData = $paynala->disburse(
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

            Log::critical('[reversements-auto] Paynala KO — intervention manuelle requise', [
                'cagnotte'        => $cagnotte->reference,
                'payout_id'       => $payoutId,
                'idempotency_key' => $idempotencyKey,
                'montant'         => $montant,
                'error'           => $e->getMessage(),
            ]);

            $this->error("    Paynala KO : {$e->getMessage()}");
            $this->envoyerAlertePaynalaKo($cagnotte, $payoutId, $transId, $montant, $numeroE164, $idempotencyKey, $e->getMessage());

            return false;
        }

        // ── Phase 3 : confirmer + post-traitement ─────────────────────────────
        DB::transaction(function () use ($payoutId, $disburseData, $cagnotte, $mode) {
            DB::table(project_table('payout'))
                ->where('id', $payoutId)
                ->update([
                    'statut'       => 'succes',
                    'operateur_id' => $disburseData['airtel_money_id'] ?? null,
                    'response'     => json_encode($disburseData),
                    'updated_at'   => now(),
                ]);

            // Mode date ou montant cible → clôturer la cagnotte.
            // Mode libre / quotidien → la cagnotte reste active.
            if ($mode !== 'libre' && $mode !== 'quotidien') {
                DB::table(project_table('cagnottes'))
                    ->where('id', $cagnotte->id)
                    ->update(['statut' => 'cloturee', 'updated_at' => now()]);
            }
        });

        // ── Notification gérant ───────────────────────────────────────────────
        $montantFmt = number_format($montant, 0, ',', ' ');
        $corps = in_array($mode, ['libre', 'quotidien'])
            ? "{$montantFmt} FCFA reversés sur « {$cagnotte->titre} »."
            : "{$montantFmt} FCFA reversés — cotisation « {$cagnotte->titre} » clôturée.";

        $notif->notifyOne(
            userId:  $cagnotte->user_id,
            titleFr: 'Reversement automatique effectué',
            bodyFr:  $corps,
            data:    [
                'type'       => 'reversement_auto',
                'cagnotte_id' => $cagnotte->id,
                'reference'  => $cagnotte->reference,
            ],
        );

        $this->info("    ✓ {$montantFmt} FCFA versés → {$msisdnLocal}" . ($mode !== 'libre' ? ' (clôturée)' : ''));

        return true;
    }

    // ── Alerte email ──────────────────────────────────────────────────────────

    /**
     * Envoie un mail d'alerte critique aux admins quand l'appel Paynala échoue.
     *
     * IMPORTANT : le solde a déjà été décrémenté en Phase 1. Un admin doit vérifier
     * manuellement si l'argent a bougé côté Paynala avant toute action corrective.
     *
     * @param  TondoCagnotte $cagnotte        Cagnotte concernée.
     * @param  string        $payoutId        UUID de la ligne tondo_payout créée.
     * @param  string        $transId         Identifiant interne de la transaction.
     * @param  int           $montant         Montant du virement tenté (FCFA).
     * @param  string        $numeroE164      Numéro E.164 du bénéficiaire.
     * @param  string        $idempotencyKey  Clé d'idempotence envoyée à Paynala.
     * @param  string        $errorMessage    Message d'erreur retourné par Paynala.
     */
    private function envoyerAlertePaynalaKo(
        TondoCagnotte $cagnotte,
        string $payoutId,
        string $transId,
        int    $montant,
        string $numeroE164,
        string $idempotencyKey,
        string $errorMessage,
    ): void {
        try {
            $destinataires = DB::table(project_table('admins'))
                ->where('actif', true)
                ->pluck('email')
                ->toArray();

            if (! empty($destinataires)) {
                Mail::to($destinataires)->send(new DisbursementFailedMail(
                    payoutId:           $payoutId,
                    transId:            $transId,
                    cagnotteReference:  $cagnotte->reference,
                    montant:            $montant,
                    numeroBeneficiaire: $numeroE164,
                    idempotencyKey:     $idempotencyKey,
                    errorMessage:       $errorMessage,
                ));
            }
        } catch (\Throwable $e) {
            Log::error('[reversements-auto] Impossible d\'envoyer DisbursementFailedMail', [
                'mail_error' => $e->getMessage(),
            ]);
        }
    }
}
