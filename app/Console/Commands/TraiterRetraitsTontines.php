<?php

namespace App\Console\Commands;

use App\Mail\DisbursementFailedMail;
use App\Mail\RetraitImpossibleMail;
use App\Models\TondoCagnotte;
use App\Services\OneSignalService;
use App\Services\PaynalaPaymentService;
use App\Services\TontineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Traite les retraits périodiques des tontines à 20h (Africa/Libreville).
 *
 * Planification : quotidienne à 20:00 dans routes/console.php.
 * Cron AWS       : * * * * * php artisan schedule:run >> /dev/null 2>&1
 *
 * Règles métier appliquées :
 *  – Retrait effectué uniquement si TOUS les membres ont cotisé ce cycle.
 *  – Si cotisations incomplètes → mail au gérant + admins, pas de retrait.
 *  – Si Paynala KO → mail admins, AUCUNE restauration automatique du solde.
 *  – Après succès → statut_paiement de tous les membres remis à en_attente.
 *  – Notification OneSignal au bénéficiaire + aux autres membres.
 */
class TraiterRetraitsTontines extends Command
{
    protected $signature   = 'tontines:traiter-retraits {--dry-run : Simule sans effectuer de transfert ni modifier la base}';
    protected $description = 'Déclenche les retraits périodiques des tontines dont l\'échéance est aujourd\'hui.';

    /**
     * Point d'entrée de la commande.
     *
     * Parcourt toutes les tontines actives et déclenche le retrait si la date
     * du cycle courant correspond à aujourd'hui et que tous les membres ont cotisé.
     *
     * @param  PaynalaPaymentService $paynala       Service de décaissement Mobile Money.
     * @param  OneSignalService      $notif          Service de notifications push.
     * @param  TontineService        $tontineService Calcule les dates de cycle.
     * @return int                                   Code de retour (self::SUCCESS).
     */
    public function handle(
        PaynalaPaymentService $paynala,
        OneSignalService      $notif,
        TontineService        $tontineService,
    ): int {
        $isDryRun = (bool) $this->option('dry-run');
        // Heure locale Gabon pour éviter un décalage de date lié à UTC.
        $today    = now()->timezone('Africa/Libreville')->toDateString();

        $this->info("[{$today}] Traitement des retraits tontines" . ($isDryRun ? ' (dry-run)' : '') . ' …');

        // Seules les tontines périodiques en cours avec une périodicité configurée.
        $tontines = TondoCagnotte::where('type', 'tontine_periodique')
            ->where('statut', 'en_cours')
            ->whereNotNull('periodicite')
            ->get();

        $this->line("  {$tontines->count()} tontine(s) en cours trouvée(s).");

        $traites = 0;
        $ignores = 0;

        foreach ($tontines as $cagnotte) {
            // Nombre de cycles réglés = nombre de payouts 'succes' enregistrés.
            $cyclesCompletes = (int) DB::table('tondo_payout')
                ->where('cagnotte_id', $cagnotte->id)
                ->where('statut', 'succes')
                ->count();

            // Rotation déjà terminée — tous les membres ont reçu leur mise.
            if ($cyclesCompletes >= (int) $cagnotte->nombre_inscrits && (int) $cagnotte->nombre_inscrits > 0) {
                $ignores++;
                continue;
            }

            // Vérifier si la date de retrait du cycle courant est bien aujourd'hui.
            $prochaineDate = $tontineService->prochaineDate($cagnotte, $cyclesCompletes);
            if ($prochaineDate !== $today) {
                $ignores++;
                continue;
            }

            $cycleActuel = $cyclesCompletes + 1;
            $this->line("  → [{$cagnotte->reference}] « {$cagnotte->titre} » — cycle {$cycleActuel}");

            // ── Vérifier que TOUS les membres ont cotisé ─────────────────
            $totalMembres = (int) DB::table('tondo_participants')
                ->where('cagnotte_id', $cagnotte->id)
                ->count();

            $participantsPaies = (int) DB::table('tondo_participants')
                ->where('cagnotte_id', $cagnotte->id)
                ->where('statut_paiement', 'paye')
                ->count();

            if ($participantsPaies < $totalMembres) {
                $nonPayes = DB::table('tondo_participants')
                    ->where('cagnotte_id', $cagnotte->id)
                    ->whereIn('statut_paiement', ['en_attente', 'en_retard'])
                    ->get(['nom', 'prenom', 'numero_masque'])
                    ->map(fn ($p) => [
                        'nom'           => $p->nom,
                        'prenom'        => $p->prenom,
                        'numero_masque' => $p->numero_masque,
                    ])
                    ->toArray();

                $this->warn("    Retrait suspendu — {$participantsPaies}/{$totalMembres} ont cotisé.");

                Log::warning('[tontines:retrait] Retrait suspendu — cotisations incomplètes', [
                    'cagnotte'  => $cagnotte->reference,
                    'cycle'     => $cycleActuel,
                    'payes'     => $participantsPaies,
                    'total'     => $totalMembres,
                    'non_payes' => array_column($nonPayes, 'numero_masque'),
                ]);

                if (! $isDryRun) {
                    $this->envoyerAlerteIncomplete($cagnotte, $cycleActuel, $participantsPaies, $totalMembres, $nonPayes, $today);
                }

                $ignores++;
                continue;
            }

            // ── Trouver le bénéficiaire du cycle ─────────────────────────────
            $beneficiaire = DB::table('tondo_participants')
                ->join('users', 'tondo_participants.user_id', '=', 'users.id')
                ->where('tondo_participants.cagnotte_id', $cagnotte->id)
                ->where('tondo_participants.ordre_passage', $cycleActuel)
                ->select(
                    'tondo_participants.id as membre_id',
                    'tondo_participants.nom',
                    'tondo_participants.prenom',
                    'users.id as user_id',
                    'users.numero as numero_e164',
                )
                ->first();

            if (! $beneficiaire || empty($beneficiaire->numero_e164)) {
                Log::error('[tontines:retrait] Bénéficiaire introuvable', [
                    'cagnotte' => $cagnotte->reference,
                    'cycle'    => $cycleActuel,
                ]);
                $ignores++;
                continue;
            }

            $montant     = (int) $cagnotte->montant_par_cycle;
            $numeroE164  = $beneficiaire->numero_e164;
            // E164 +24177XXXXXX → local 077XXXXXX
            $msisdnLocal = str_starts_with($numeroE164, '+241')
                ? '0' . substr($numeroE164, 4)
                : ltrim($numeroE164, '+');

            $this->line("    Bénéficiaire : {$beneficiaire->prenom} {$beneficiaire->nom} ({$msisdnLocal}) — {$montant} FCFA");

            if ($isDryRun) {
                $this->info('    [dry-run] Virement non effectué.');
                $traites++;
                continue;
            }

            // ── Génération des identifiants Paynala ───────────────────────────
            $nextNum        = DB::table('tondo_payout')->count() + 1;
            $reference      = 'TONDODISBURSEMENT' . now()->getTimestampMs();
            $idempotencyKey = 'TONDO-TONTINE-' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
            $payoutId       = (string) Str::uuid();
            $transId        = 'TONDOPAYOUT' . strtoupper(Str::random(9));

            // ── Phase 1 : réserver sous row-lock ─────────────────────────────
            try {
                DB::transaction(function () use (
                    $cagnotte, $beneficiaire, $montant, $cycleActuel,
                    $payoutId, $transId, $idempotencyKey, $reference, $numeroE164
                ) {
                    $solde = (int) DB::table('tondo_cagnottes')
                        ->where('id', $cagnotte->id)
                        ->lockForUpdate()
                        ->value('montant_collecte');

                    if ($solde < $montant) {
                        throw new \RuntimeException("Solde insuffisant : {$solde} FCFA disponibles, {$montant} FCFA requis.");
                    }

                    DB::table('tondo_payout')->insert([
                        'id'            => $payoutId,
                        'project_id'    => $cagnotte->project_id,
                        'cagnotte_id'   => $cagnotte->id,
                        'user_id'       => $beneficiaire->user_id,
                        'trans_id'      => $transId,
                        'operateur_id'  => null,
                        'numero_tel'    => $numeroE164,
                        'montant'       => $montant,
                        'statut'        => 'initie',
                        'request'       => json_encode([
                            'idempotency_key' => $idempotencyKey,
                            'reference'       => $reference,
                            'cycle'           => $cycleActuel,
                            'beneficiaire'    => "{$beneficiaire->prenom} {$beneficiaire->nom}",
                            'source'          => 'cron_20h',
                        ]),
                        'date_creation' => now(),
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);

                    DB::table('tondo_cagnottes')
                        ->where('id', $cagnotte->id)
                        ->update([
                            'montant_collecte' => DB::raw("montant_collecte - {$montant}"),
                            'updated_at'       => now(),
                        ]);
                });
            } catch (\Throwable $e) {
                Log::error('[tontines:retrait] Échec réservation DB', [
                    'cagnotte' => $cagnotte->reference,
                    'error'    => $e->getMessage(),
                ]);
                $this->error("    Échec réservation : {$e->getMessage()}");
                $ignores++;
                continue;
            }

            // ── Phase 2 : appel Paynala ───────────────────────────────────────
            $disburseType = $paynala->resolveDisburseType(
                msisdnLocal: $msisdnLocal,
                msisdnE164:  $numeroE164,
                userId:      $beneficiaire->user_id,
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
                // Paynala KO — NE PAS restaurer le solde, alerter les admins.
                DB::table('tondo_payout')
                    ->where('id', $payoutId)
                    ->update([
                        'statut'     => 'echec',
                        'response'   => json_encode(['error' => $e->getMessage()]),
                        'updated_at' => now(),
                    ]);

                Log::critical('[tontines:retrait] Paynala KO — intervention manuelle requise', [
                    'cagnotte'        => $cagnotte->reference,
                    'payout_id'       => $payoutId,
                    'idempotency_key' => $idempotencyKey,
                    'montant'         => $montant,
                    'error'           => $e->getMessage(),
                ]);

                $this->error("    Paynala KO : {$e->getMessage()}");
                $this->envoyerAlertePaynalaKo($cagnotte, $payoutId, $transId, $montant, $numeroE164, $idempotencyKey, $e->getMessage());
                $ignores++;
                continue;
            }

            // ── Phase 3 : confirmer + réinitialiser cycle ─────────────────────
            DB::transaction(function () use ($payoutId, $disburseData, $cagnotte) {
                DB::table('tondo_payout')
                    ->where('id', $payoutId)
                    ->update([
                        'statut'       => 'succes',
                        'operateur_id' => $disburseData['airtel_money_id'] ?? null,
                        'response'     => json_encode($disburseData),
                        'updated_at'   => now(),
                    ]);

                // Remettre tous les membres à en_attente pour le cycle suivant.
                DB::table('tondo_participants')
                    ->where('cagnotte_id', $cagnotte->id)
                    ->update([
                        'statut_paiement' => 'en_attente',
                        'updated_at'      => now(),
                    ]);
            });

            // ── Notifications ─────────────────────────────────────────────────
            if ($beneficiaire->user_id) {
                $notif->notifyOne(
                    userId:  $beneficiaire->user_id,
                    titleFr: 'Votre mise vous a été versée !',
                    bodyFr:  number_format($montant, 0, ',', ' ') . " FCFA viennent d'être envoyés sur votre compte Mobile Money.",
                    data:    ['type' => 'retrait_tontine', 'cagnotte_id' => $cagnotte->id],
                );
            }

            $autresIds = DB::table('tondo_participants')
                ->join('users', 'tondo_participants.user_id', '=', 'users.id')
                ->where('tondo_participants.cagnotte_id', $cagnotte->id)
                ->where('users.compte_type', 'full')
                ->where('users.id', '!=', $beneficiaire->user_id)
                ->pluck('users.id')
                ->filter()
                ->values()
                ->all();

            if (! empty($autresIds)) {
                $notif->notify(
                    userIds: $autresIds,
                    titleFr: 'Nouveau cycle — cotisez maintenant',
                    bodyFr:  "Le cycle {$cycleActuel} de « {$cagnotte->titre} » est clôturé. Le suivant commence.",
                    data:    ['type' => 'nouveau_cycle', 'cagnotte_id' => $cagnotte->id],
                );
            }

            // Notifier le PROCHAIN bénéficiaire (cycle + 1) qu'il sera le suivant.
            $prochainOrdre = $cycleActuel + 1;
            $prochainBenef = DB::table('tondo_participants')
                ->join('users', 'tondo_participants.user_id', '=', 'users.id')
                ->where('tondo_participants.cagnotte_id', $cagnotte->id)
                ->where('tondo_participants.ordre_passage', $prochainOrdre)
                ->where('users.compte_type', 'full')
                ->select('users.id as user_id', 'tondo_participants.prenom', 'tondo_participants.nom')
                ->first();

            if ($prochainBenef && $prochainBenef->user_id) {
                $prochaineDateStr = $tontineService->prochaineDate($cagnotte, $cycleActuel);
                $corps = $prochaineDateStr
                    ? "Vous êtes le prochain bénéficiaire de « {$cagnotte->titre} ». Votre mise vous sera versée le {$prochaineDateStr}."
                    : "Vous êtes le prochain bénéficiaire de « {$cagnotte->titre} » — préparez-vous !";

                $notif->notifyOne(
                    userId:  $prochainBenef->user_id,
                    titleFr: "C'est bientôt votre tour !",
                    bodyFr:  $corps,
                    data:    ['type' => 'prochain_beneficiaire', 'cagnotte_id' => $cagnotte->id],
                );
            } elseif ($prochainOrdre > (int) $cagnotte->nombre_inscrits) {
                // Dernier cycle terminé — notifier tout le groupe.
                $tousIds = DB::table('tondo_participants')
                    ->join('users', 'tondo_participants.user_id', '=', 'users.id')
                    ->where('tondo_participants.cagnotte_id', $cagnotte->id)
                    ->where('users.compte_type', 'full')
                    ->pluck('users.id')->filter()->values()->all();

                if (! empty($tousIds)) {
                    $notif->notify(
                        userIds: $tousIds,
                        titleFr: 'Rotation terminée 🎉',
                        bodyFr:  "Tous les membres de « {$cagnotte->titre} » ont reçu leur mise. Merci à tous !",
                        data:    ['type' => 'rotation_terminee', 'cagnotte_id' => $cagnotte->id],
                    );
                }
            }

            $this->info("    ✓ {$montant} FCFA versés à {$beneficiaire->prenom} {$beneficiaire->nom}");
            $traites++;
        }

        $this->info("Terminé — {$traites} retrait(s) effectué(s), {$ignores} ignoré(s).");
        return self::SUCCESS;
    }

    // ── Alertes mail ──────────────────────────────────────────────────────────

    /**
     * Envoie un mail d'alerte quand le retrait est suspendu faute de cotisations complètes.
     *
     * Destinataires : tous les admins actifs + le gérant de la cagnotte (s'il a un email).
     *
     * @param  TondoCagnotte $cagnotte       Tontine concernée.
     * @param  int           $cycle          Numéro du cycle en cours.
     * @param  int           $nombrePayes    Nombre de membres ayant cotisé.
     * @param  int           $nombreTotal    Nombre total de membres attendus.
     * @param  array         $nonPayes       Liste des membres non payés (nom, prénom, numéro masqué).
     * @param  string        $dateRetrait    Date prévue du retrait (format 'Y-m-d').
     */
    private function envoyerAlerteIncomplete(
        TondoCagnotte $cagnotte,
        int    $cycle,
        int    $nombrePayes,
        int    $nombreTotal,
        array  $nonPayes,
        string $dateRetrait,
    ): void {
        try {
            $destinataires = $this->destinatairesAdmins();

            // Ajouter le gérant s'il a un email — il doit être informé de la situation.
            $gerantEmail = DB::table('users')
                ->where('id', $cagnotte->user_id)
                ->value('email');
            if ($gerantEmail) {
                $destinataires[] = $gerantEmail;
            }

            if (! empty($destinataires)) {
                // array_unique évite les doublons si le gérant est aussi admin.
                Mail::to(array_unique($destinataires))->send(new RetraitImpossibleMail(
                    cagnotteReference: $cagnotte->reference,
                    cagnotteTitre:     $cagnotte->titre,
                    cycle:             $cycle,
                    nombrePayes:       $nombrePayes,
                    nombreTotal:       $nombreTotal,
                    nonPayes:          $nonPayes,
                    dateRetrait:       $dateRetrait,
                ));
            }
        } catch (\Throwable $e) {
            Log::error('[tontines:retrait] Impossible d\'envoyer RetraitImpossibleMail', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Envoie un mail d'alerte critique quand l'appel Paynala échoue après réservation.
     *
     * IMPORTANT : le solde a déjà été décrémenté en Phase 1. Un admin doit vérifier
     * manuellement si l'argent a bougé côté Paynala avant toute action corrective.
     *
     * @param  TondoCagnotte $cagnotte        Tontine concernée.
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
            $destinataires = $this->destinatairesAdmins();
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
            Log::error('[tontines:retrait] Impossible d\'envoyer DisbursementFailedMail', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Retourne la liste des emails des admins actifs pour les alertes.
     *
     * @return string[] Tableau d'adresses email.
     */
    private function destinatairesAdmins(): array
    {
        return DB::table('tondo_admins')
            ->where('actif', true)
            ->pluck('email')
            ->toArray();
    }
}
