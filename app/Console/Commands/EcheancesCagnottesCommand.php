<?php

namespace App\Console\Commands;

use App\Contracts\PushNotifier;
use App\Services\EcheanceCagnotte;
use App\Services\Mail\EmailLayout;
use App\Services\Mail\MailgunSender;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tient à jour l'échéance des cagnottes ouvertes et prévient avant la clôture.
 *
 * Deux tâches, dans cet ordre, et l'ordre compte : on recalcule d'abord, on
 * prévient ensuite, pour qu'un réglage changé le matin produise les bons
 * rappels le jour même plutôt que le lendemain.
 *
 * Cette commande ne touche JAMAIS à l'argent. Le rapatriement et la clôture
 * restent au reversement automatique de 18 h, qui sait déjà le faire et qui
 * porte les garde-fous — verrou des sorties, suspension, échec Paynala. Deux
 * chemins vers le même décaissement, c'est deux fois les mêmes bugs.
 *
 * Planifiée à 08h00 : avant le cron de 18 h, et à une heure où un rappel se
 * lit.
 */
class EcheancesCagnottesCommand extends Command
{
    protected $signature = 'tonji:echeances-cagnottes
        {--dry-run : Calcule et affiche sans rien écrire ni envoyer}';

    protected $description = 'Recalcule l\'échéance des cagnottes ouvertes et envoie les rappels avant clôture.';

    public function handle(
        EcheanceCagnotte $echeances,
        PushNotifier     $push,
        MailgunSender    $mailer,
    ): int {
        $sec        = (bool) $this->option('dry-run');
        $aujourdhui = now()->timezone('Africa/Libreville')->startOfDay();

        $this->info("[{$aujourdhui->toDateString()}] Échéances des cagnottes" . ($sec ? ' (à blanc)' : '') . ' …');

        $cagnottes = DB::table(project_table('cagnottes'))
            ->where('type', EcheanceCagnotte::TYPE_CONCERNE)
            ->whereIn('statut', ['active', 'en_cours'])
            ->get(['id', 'project_id', 'user_id', 'reference', 'titre', 'type',
                   'statut', 'date_creation', 'date_fin', 'date_echeance']);

        $poses = 0;
        $rappeles = 0;

        foreach ($cagnottes as $cagnotte) {
            $jours = $echeances->dureeMax((string) $cagnotte->project_id);

            // ── 1. L'échéance ────────────────────────────────────────────────
            $calculee = $echeances->calculer($cagnotte, $jours, $aujourdhui);

            if ($calculee !== ($cagnotte->date_echeance ? Carbon::parse($cagnotte->date_echeance)->toDateString() : null)) {
                if (! $sec) {
                    DB::table(project_table('cagnottes'))
                        ->where('id', $cagnotte->id)
                        ->update(['date_echeance' => $calculee]);
                }
                $cagnotte->date_echeance = $calculee;
                $poses++;
                $this->line("  {$cagnotte->reference} : échéance → " . ($calculee ?? 'aucune'));
            }

            // ── 2. Le rappel ─────────────────────────────────────────────────
            // Sur la date EFFECTIVE : si l'organisateur s'est donné une fin
            // plus proche, c'est elle qui approche, et c'est d'elle qu'il faut
            // le prévenir.
            $palier = $echeances->palierDuJour($echeances->effective($cagnotte), $aujourdhui);

            if ($palier !== null && ! $sec && $this->rappeler($cagnotte, $palier, $push, $mailer)) {
                $rappeles++;
            } elseif ($palier !== null && $sec) {
                $this->line("  {$cagnotte->reference} : rappel J-{$palier}");
                $rappeles++;
            }
        }

        $this->info("  {$cagnottes->count()} cagnotte(s) examinée(s), {$poses} échéance(s) posée(s), {$rappeles} rappel(s).");

        return self::SUCCESS;
    }

    /**
     * Envoie le rappel d'un palier, une seule fois.
     *
     * L'unicité est tenue par l'INDEX, pas par une lecture préalable : deux
     * exécutions concurrentes liraient toutes deux « pas encore envoyé » et
     * l'organisateur recevrait le message en double. On tente l'insertion, et
     * c'est son échec qui nous apprend qu'un autre est passé avant.
     *
     * @return bool vrai si le rappel est bien parti de notre fait
     */
    private function rappeler(
        object        $cagnotte,
        int           $palier,
        PushNotifier  $push,
        MailgunSender $mailer,
    ): bool {
        $canaux = ['push'];
        $email  = null;

        if (in_array($palier, EcheanceCagnotte::PALIERS_AVEC_EMAIL, true)) {
            $email = DB::table('users')->where('id', $cagnotte->user_id)->value('email');
            if ($email) {
                $canaux[] = 'email';
            }
        }

        try {
            DB::table(project_table('rappels_echeance'))->insert([
                'id'          => (string) Str::uuid(),
                'project_id'  => $cagnotte->project_id,
                'cagnotte_id' => $cagnotte->id,
                'palier'      => $palier,
                'canaux'      => implode(',', $canaux),
            ]);
        } catch (\Throwable $e) {
            // Déjà envoyé : l'index a fait son travail, il n'y a rien à dire.
            return false;
        }

        $quand  = $this->enClair($palier);
        $nom    = $cagnotte->titre ?: "la cagnotte {$cagnotte->reference}";
        $titre  = 'Clôture dans ' . $quand;
        $corps  = "« {$nom} » sera clôturée dans {$quand}. Les fonds collectés "
            . 'seront automatiquement reversés sur le numéro de retrait.';

        $push->notifyOne((string) $cagnotte->user_id, $titre, $corps, [
            'type'      => 'echeance_cagnotte',
            'cagnotte'  => (string) $cagnotte->reference,
            'palier'    => (string) $palier,
        ]);

        if ($email) {
            try {
                $mailer->envoyer($email, "Tonji — {$titre} : {$nom}", EmailLayout::render(
                    "Clôture dans {$quand}",
                    "<p>Votre cagnotte <strong>" . e($nom) . "</strong> (réf. "
                        . e((string) $cagnotte->reference) . ") sera clôturée dans <strong>{$quand}</strong>.</p>"
                    . '<p>À cette date, les fonds collectés seront automatiquement reversés '
                        . 'sur le numéro de retrait enregistré à la création, et la cagnotte '
                        . 'sera fermée. Vous n\'avez rien à faire.</p>',
                ));
            } catch (\Throwable $e) {
                // Le courriel est un renfort du push, pas l'inverse : son échec
                // ne doit pas faire croire que le rappel n'est pas parti.
                Log::warning('[echeances] courriel de rappel non envoyé', [
                    'cagnotte' => $cagnotte->reference,
                    'palier'   => $palier,
                    'erreur'   => $e->getMessage(),
                ]);
            }
        }

        $this->line("  {$cagnotte->reference} : rappel J-{$palier} (" . implode(' + ', $canaux) . ')');

        return true;
    }

    /** « 1 jour », « 3 jours », « 1 semaine »… — ce qu'un humain lit. */
    private function enClair(int $jours): string
    {
        return match ($jours) {
            30      => '1 mois',
            14      => '2 semaines',
            7       => '1 semaine',
            1       => '1 jour',
            default => "{$jours} jours",
        };
    }
}
