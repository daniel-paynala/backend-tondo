<?php

namespace App\Console\Commands;

use App\Models\TondoUser;
use App\Services\WhatsApp\Contracts\WhatsAppSender;
use App\Services\WhatsApp\MetaSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Résumé quotidien du soir envoyé aux créateurs de cagnottes.
 *
 * Notification PROACTIVE (template WhatsApp) : « ce que tu as reçu aujourd'hui »,
 * détaillé par cagnotte. N'est envoyé qu'aux créateurs dont AU MOINS UNE cagnotte
 * a reçu une cotisation dans la journée (« seulement s'il y a eu du mouvement »).
 *
 * Coût maîtrisé : au plus 1 message payant par jour et par créateur actif.
 *
 * Gating : ne fait rien si le template n'est pas configuré
 * (services.whatsapp.templates.resume_quotidien) ou si le fournisseur n'est pas
 * Meta. Planifié dans routes/console.php (20h Africa/Libreville).
 *
 * BotService n'est pas concerné : cette commande lit la table `paiements` et
 * envoie directement via le sender.
 */
class ResumeQuotidienCommand extends Command
{
    protected $signature   = 'tonji:resume-quotidien {--dry-run : Affiche sans envoyer}';
    protected $description = 'Envoie aux créateurs le résumé du soir des cotisations reçues aujourd\'hui (template WhatsApp).';

    /** Nombre max de cagnottes détaillées dans le message (au-delà : « et N autres »). */
    private const MAX_DETAIL = 5;

    public function handle(WhatsAppSender $sender): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Template requis (sinon la fonctionnalité est simplement désactivée).
        $template = config('services.whatsapp.templates.resume_quotidien');
        $langue   = config('services.whatsapp.templates.langue', 'fr');
        if (empty($template)) {
            $this->warn('Template resume_quotidien non configuré → rien à envoyer.');
            return self::SUCCESS;
        }

        // Les templates ne partent que via Meta ; sur un autre fournisseur, on s'abstient.
        if (! $sender instanceof MetaSenderService && ! $dryRun) {
            $this->warn('Fournisseur non-Meta → templates indisponibles, rien envoyé.');
            return self::SUCCESS;
        }

        // Bornes du jour en heure locale du Gabon.
        $debut = now()->timezone('Africa/Libreville')->startOfDay();
        $fin   = (clone $debut)->addDay();

        $paiements = project_table('paiements');
        $cagnottes = project_table('cagnottes');

        // Cotisations confirmées aujourd'hui, agrégées PAR CAGNOTTE (donc par créateur).
        $lignes = DB::table($paiements)
            ->join($cagnottes, "{$cagnottes}.id", '=', "{$paiements}.cagnotte_id")
            ->whereBetween("{$paiements}.date", [$debut, $fin])
            ->groupBy("{$cagnottes}.user_id", "{$cagnottes}.id", "{$cagnottes}.titre")
            ->selectRaw("{$cagnottes}.user_id as createur_id, {$cagnottes}.titre as titre, SUM({$paiements}.montant) as total, COUNT(*) as nb")
            ->orderByDesc('total')
            ->get();

        // Regroupement par créateur (chaque créateur = 1 message récapitulant ses cagnottes).
        $parCreateur = $lignes->groupBy('createur_id');
        $this->info("[{$debut->toDateString()}] Résumé quotidien — {$parCreateur->count()} créateur(s) avec mouvement.");

        $envoyes = 0;
        foreach ($parCreateur as $createurId => $cagnottesDuJour) {
            $user = TondoUser::find($createurId);
            if (! $user || empty($user->numero)) {
                continue;
            }

            $prenom      = ucfirst(mb_strtolower((string) $user->prenom)) ?: 'à toi';
            $totalGlobal = (float) $cagnottesDuJour->sum('total');
            $detail      = $this->construireDetail($cagnottesDuJour);
            $totalFmt    = number_format($totalGlobal, 0, ',', ' ');

            if ($dryRun) {
                $this->line("  [dry] {$user->numero} → {$detail} | Total {$totalFmt} FCFA");
                continue;
            }

            // Variables du template : {{1}} prénom, {{2}} détail, {{3}} total.
            $ok = $sender->envoyerTemplate($user->numero, $template, $langue, [$prenom, $detail, $totalFmt]);
            if ($ok) {
                $envoyes++;
            } else {
                Log::warning('tonji:resume-quotidien: envoi template échoué', ['user_id' => $createurId]);
            }
        }

        $this->info("✅ {$envoyes} résumé(s) envoyé(s).");
        return self::SUCCESS;
    }

    /**
     * Construit le détail : UNE LIGNE PAR CAGNOTTE (puce « • titre : montant (nb) »),
     * plafonné à MAX_DETAIL (« et N autre(s) » au-delà).
     *
     * NB : les lignes sont séparées par des retours à la ligne (\n) — Meta les
     * accepte dans les paramètres de template. Si un envoi réel est refusé pour
     * cause de nouvelle ligne, repasser à un séparateur « · » (implode(' · ')).
     *
     * @param  \Illuminate\Support\Collection $cagnottes  Lignes agrégées par cagnotte.
     * @return string
     */
    private function construireDetail(\Illuminate\Support\Collection $cagnottes): string
    {
        $parts = $cagnottes->take(self::MAX_DETAIL)->map(function ($c) {
            $montant = number_format((float) $c->total, 0, ',', ' ');
            return '• ' . trim((string) $c->titre) . " : {$montant} FCFA ({$c->nb})";
        })->all();

        $reste = $cagnottes->count() - self::MAX_DETAIL;
        if ($reste > 0) {
            $parts[] = "• et {$reste} autre(s)";
        }

        return implode("\n", $parts);
    }
}
