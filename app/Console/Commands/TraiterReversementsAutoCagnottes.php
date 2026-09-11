<?php

namespace App\Console\Commands;

use App\Mail\DisbursementFailedMail;
use App\Models\TondoCagnotte;
use App\Contracts\PushNotifier;
use App\Services\ReversementService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
     * @param  PushNotifier      $notif   Service de notifications push.
     * @return int                            Code de retour (self::SUCCESS).
     */
    public function handle(
        ReversementService $reversements,
        PushNotifier       $notif,
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

            $ok = $this->traiter($cagnotte, $mode, $reversements, $notif);
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
     * @param  PushNotifier      $notif    Service de notifications push.
     * @return bool                            True si le reversement a réussi, false sinon.
     */
    private function traiter(
        TondoCagnotte      $cagnotte,
        string             $mode,
        ReversementService $reversements,
        PushNotifier       $notif,
    ): bool {
        $montant = (int) $cagnotte->montant_collecte;

        // Le décaissement lui-même vit dans ReversementService, partagé avec la
        // suppression de compte : réservation sous row-lock, appel Paynala, puis
        // restauration du solde si le refus est explicite. Cette méthode ne garde
        // que l'habillage propre au cron — sortie console, alerte, notification.
        //
        // Modes 'libre' et 'quotidien' : la cagnotte continue de collecter, on ne
        // la clôture pas. Les autres modes (date limite, montant cible) la ferment.
        $res = $reversements->reverserSolde(
            cagnotte:     $cagnotte,
            source:       'cron_reversement_auto',
            prefixeIdem:  'TONDO-AUTO-',
            prefixeTrans: 'TONDOAUTO',
            cloturer:     ! in_array($mode, ['libre', 'quotidien'], true),
            trace:        ['mode' => $mode],
        );

        if (! $res['ok']) {
            $this->error("    {$res['erreur']}");

            // Alerte seulement si un payout a réellement été créé : une réservation
            // qui n'a pas abouti n'a pas d'identifiant à instruire.
            if ($res['payout_id'] !== null) {
                $this->envoyerAlertePaynalaKo(
                    $cagnotte,
                    $res['payout_id'],
                    (string) $res['trans_id'],
                    $res['montant'],
                    (string) $cagnotte->numero_retrait,
                    (string) $res['idempotency_key'],
                    (string) $res['erreur'],
                );
            }

            return false;
        }

        // ── Notification gérant ───────────────────────────────────────────────
        $montantFmt = number_format($montant, 0, ',', ' ');
        $corps = in_array($mode, ['libre', 'quotidien'])
            ? "{$montantFmt} FCFA transférés sur « {$cagnotte->titre} »."
            : "{$montantFmt} FCFA transférés — cotisation « {$cagnotte->titre} » clôturée.";

        $notif->notifyOne(
            userId:  $cagnotte->user_id,
            titleFr: 'Transfert automatique effectué',
            bodyFr:  $corps,
            data:    [
                'type'       => 'reversement_auto',
                'cagnotte_id' => $cagnotte->id,
                'reference'  => $cagnotte->reference,
            ],
        );

        $this->info("    ✓ {$montantFmt} FCFA versés → {$cagnotte->numero_retrait}" . ($mode !== 'libre' ? ' (clôturée)' : ''));

        return true;
    }

    // ── Alerte email ──────────────────────────────────────────────────────────

    /**
     * Envoie un mail d'alerte critique aux admins quand l'appel Paynala échoue.
     *
     * IMPORTANT : l'état du solde dépend de la nature de l'échec. Refus explicite
     * de Paynala → le solde a été RESTAURÉ, l'argent n'est pas parti. Timeout ou
     * réseau → l'issue est inconnue, le solde reste amputé et un admin doit
     * vérifier côté Paynala avant toute action corrective. Le message d'erreur
     * transmis ici précise lequel des deux cas s'est produit.
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
