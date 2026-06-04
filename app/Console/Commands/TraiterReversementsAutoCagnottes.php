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
 * Planification : quotidienne à 08h00 (Africa/Libreville) dans routes/console.php.
 *
 * Trois modes de déclenchement (priorité décroissante) :
 *  1. Date limite atteinte   — date_fin <= aujourd'hui
 *  2. Montant cible atteint  — montant_collecte >= montant_cible
 *  3. Fréquence libre        — reversement_auto_frequence_mois : tous les N mois
 *     depuis la dernière opération réussie (ou depuis la création si aucune).
 *
 * Après succès :
 *  - Mode date / montant → cagnotte clôturée automatiquement (statut = cloturee).
 *  - Mode libre → cagnotte reste active ; le prochain cycle reprend.
 *
 * En cas d'échec Paynala : log critique + mail admins, solde non restauré
 * (même politique que TraiterRetraitsTontines).
 */
class TraiterReversementsAutoCagnottes extends Command
{
    protected $signature   = 'cotisations:reversements-auto {--dry-run : Simule sans effectuer de transfert ni modifier la base}';
    protected $description = 'Déclenche les reversements automatiques des cotisations ouvertes.';

    public function handle(
        PaynalaPaymentService $paynala,
        OneSignalService      $notif,
    ): int {
        $isDryRun = (bool) $this->option('dry-run');
        $today    = now()->timezone('Africa/Libreville')->toDateString();

        $this->info("[{$today}] Reversements auto cotisations" . ($isDryRun ? ' (dry-run)' : '') . ' …');

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
     * Retourne le mode de déclenchement applicable, ou null si pas encore le moment.
     */
    private function determinerMode(TondoCagnotte $cagnotte, string $today): ?string
    {
        // Priorité 1 : date limite atteinte.
        if ($cagnotte->date_fin && $cagnotte->date_fin->toDateString() <= $today) {
            return 'date';
        }

        // Priorité 2 : montant cible atteint ou dépassé.
        if ($cagnotte->montant_cible && (int) $cagnotte->montant_collecte >= (int) $cagnotte->montant_cible) {
            return 'montant_cible';
        }

        // Priorité 3 : fréquence libre (N mois).
        if ($cagnotte->reversement_auto_frequence_mois) {
            $dernierPayout = DB::table('tondo_payout')
                ->where('cagnotte_id', $cagnotte->id)
                ->where('statut', 'succes')
                ->max('date_creation');

            $reference = $dernierPayout
                ? Carbon::parse($dernierPayout)->timezone('Africa/Libreville')
                : $cagnotte->date_creation->timezone('Africa/Libreville');

            $prochainDeclenchement = $reference->copy()
                ->addMonths((int) $cagnotte->reversement_auto_frequence_mois);

            if ($prochainDeclenchement->toDateString() <= $today) {
                return 'libre';
            }
        }

        return null;
    }

    // ── Exécution du reversement ──────────────────────────────────────────────

    private function traiter(
        TondoCagnotte         $cagnotte,
        string                $mode,
        PaynalaPaymentService $paynala,
        OneSignalService      $notif,
    ): bool {
        $montant    = (int) $cagnotte->montant_collecte;
        $numeroE164 = $cagnotte->numero_retrait;
        $msisdnLocal = str_starts_with($numeroE164, '+241')
            ? '0' . substr($numeroE164, 4)
            : ltrim($numeroE164, '+');

        $nextNum        = DB::table('tondo_payout')->count() + 1;
        $reference      = 'TONDODISBURSEMENT' . now()->getTimestampMs();
        $idempotencyKey = 'TONDO-AUTO-' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
        $payoutId       = (string) Str::uuid();
        $transId        = 'TONDOAUTO' . strtoupper(Str::random(9));

        // Résoudre l'user_id du bénéficiaire (si numéro enregistré).
        $beneficiaireUserId = DB::table('users')
            ->where('numero', $numeroE164)
            ->value('id');

        // ── Phase 1 : réserver sous row-lock ─────────────────────────────────
        try {
            DB::transaction(function () use (
                $cagnotte, $montant, $payoutId, $transId,
                $idempotencyKey, $reference, $numeroE164, $beneficiaireUserId, $mode
            ) {
                $solde = (int) DB::table('tondo_cagnottes')
                    ->where('id', $cagnotte->id)
                    ->lockForUpdate()
                    ->value('montant_collecte');

                if ($solde < $montant || $solde <= 0) {
                    throw new \RuntimeException("Solde insuffisant ou nul : {$solde} FCFA.");
                }

                DB::table('tondo_payout')->insert([
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

                DB::table('tondo_cagnottes')
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
            DB::table('tondo_payout')
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
            DB::table('tondo_payout')
                ->where('id', $payoutId)
                ->update([
                    'statut'       => 'succes',
                    'operateur_id' => $disburseData['airtel_money_id'] ?? null,
                    'response'     => json_encode($disburseData),
                    'updated_at'   => now(),
                ]);

            // Mode date ou montant cible → clôturer la cagnotte.
            if ($mode !== 'libre') {
                DB::table('tondo_cagnottes')
                    ->where('id', $cagnotte->id)
                    ->update(['statut' => 'cloturee', 'updated_at' => now()]);
            }
            // Mode libre → la cagnotte reste active pour le prochain cycle.
        });

        // ── Notification gérant ───────────────────────────────────────────────
        $montantFmt = number_format($montant, 0, ',', ' ');
        $corps = $mode === 'libre'
            ? "{$montantFmt} FCFA reversés automatiquement sur « {$cagnotte->titre} »."
            : "{$montantFmt} FCFA reversés automatiquement — cotisation « {$cagnotte->titre} » clôturée.";

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
            $destinataires = DB::table('tondo_admins')
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
