<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\CotisationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Réconciliation des paiements restés en `initie` (bot ET app).
 *
 * Le polling de l'app et le cron WhatsApp (timeout 3 min) peuvent **rater** une
 * confirmation Airtel **tardive** (Airtel confirme souvent après plusieurs
 * minutes) : le `payin` reste `initie`, l'argent est débité côté client mais la
 * cagnotte n'est **jamais créditée**, et plus rien ne re-vérifie derrière.
 *
 * Ce balayage re-interroge l'agrégateur pour chaque `payin` `initie` récent
 * (via {@see CotisationService::verifierStatut}) et **crédite** ceux réellement
 * `SUCCESS`. Idempotent : le crédit passe par le **claim atomique**
 * (`WHERE statut='initie'`) + l'index unique sur `paiements.trans_id` → aucun
 * double crédit possible, même si le polling repasse en même temps.
 *
 * Options :
 *   --jours=3       Fenêtre (jours) des `payin` `initie` à revérifier (défaut 3).
 *   --trans-id=XXX  Ne traiter qu'un seul `trans_id` (récupération ponctuelle,
 *                   hors fenêtre — utile pour rattraper un cas précis).
 */
class ReconcilierPayinsCommand extends Command
{
    protected $signature = 'tonji:reconcilier-payins
        {--jours=3 : Fenêtre en jours des payin initie à revérifier}
        {--trans-id= : Ne traiter qu\'un trans_id précis (hors fenêtre)}';

    protected $description = 'Re-vérifie les payin restés initie et crédite ceux confirmés (récupère les paiements débités non captés).';

    public function handle(CotisationService $svc): int
    {
        $transId = $this->option('trans-id');
        $jours   = (int) $this->option('jours');

        // Cible : les payin encore en attente. Soit un trans_id précis, soit la
        // fenêtre récente pour le balayage périodique.
        $query = DB::table(project_table('payin'))->where('statut', 'initie');
        if ($transId) {
            $query->where('trans_id', $transId);
        } else {
            $query->where('date_creation', '>=', now()->subDays($jours));
        }
        $payins = $query->orderBy('date_creation')->limit(500)->get();

        $this->info("Payin en 'initie' à revérifier : {$payins->count()}");

        $credites = 0;
        $attente  = 0;
        $echecs   = 0;

        foreach ($payins as $p) {
            try {
                // verifierStatut re-interroge Paynala et, sur SUCCESS, crédite via
                // crediterSurSucces (claim atomique). Sur PENDING → laisse initie.
                $statut = $svc->verifierStatut($p->trans_id, $p->project_id);
            } catch (\Throwable $e) {
                Log::error('tonji:reconcilier-payins: erreur verifierStatut', [
                    'trans_id' => $p->trans_id,
                    'err'      => $e->getMessage(),
                ]);
                $this->warn("  {$p->trans_id} : erreur — {$e->getMessage()}");
                continue;
            }

            if ($statut === 'succes') {
                $credites++;
                $this->line("  {$p->trans_id} : ✅ crédité");
            } elseif ($statut === 'echec') {
                $echecs++;
                $this->line("  {$p->trans_id} : ❌ échec (Paynala)");
            } else {
                $attente++;
                $this->line("  {$p->trans_id} : ⏳ toujours en attente");
            }
        }

        $this->info("Terminé — crédités : {$credites} · en attente : {$attente} · échecs : {$echecs}");
        return self::SUCCESS;
    }
}
