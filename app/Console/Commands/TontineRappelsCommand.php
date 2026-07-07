<?php

namespace App\Console\Commands;

use App\Models\TondoCagnotte;
use App\Services\OneSignalService;
use App\Services\TontineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Envoie des rappels de cotisation aux membres qui n'ont pas encore
 * payé, à J-5, J-2, J (jour du retrait) et J+1 (retard).
 *
 * Planification : quotidienne à 09h00 (Africa/Libreville).
 * Déclenchée par schedule:run via le cron système.
 *
 * Rappels envoyés :
 *  – J-5  : « Cotisez dans 5 jours »
 *  – J-2  : « Cotisez dans 2 jours »
 *  – J    : « C'est aujourd'hui le jour du retrait — cotisez maintenant »
 *  – J+1  : « Vous êtes en retard »
 */
class TontineRappelsCommand extends Command
{
    protected $signature   = 'tontines:rappels {--dry-run : Affiche les rappels sans les envoyer}';
    protected $description = 'Envoie les rappels de cotisation aux membres en retard ou proches de l\'échéance.';

    /**
     * Jours à surveiller (en jours relatifs à la date de retrait).
     * Négatif = retard (retrait déjà passé), 0 = aujourd'hui, positif = à venir.
     */
    private const JOURS = [-1, 0, 2, 5];

    /**
     * Point d'entrée de la commande.
     *
     * @param  TontineService  $tontineService Calcule la prochaine date de retrait.
     * @param  OneSignalService $notif          Envoie les notifications push.
     * @return int Code de retour (self::SUCCESS ou self::FAILURE).
     */
    public function handle(TontineService $tontineService, OneSignalService $notif): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        // Heure locale Gabon pour éviter les décalages UTC.
        $today    = now()->timezone('Africa/Libreville')->startOfDay();

        $this->info("[{$today->toDateString()}] Rappels de cotisation tontines" . ($isDryRun ? ' (dry-run)' : '') . ' …');

        // Seules les tontines périodiques actives avec une périodicité définie sont traitées.
        $tontines = TondoCagnotte::where('type', 'tontine_periodique')
            ->where('statut', 'en_cours')
            ->whereNotNull('periodicite')
            ->get();

        $this->line("  {$tontines->count()} tontine(s) en cours.");

        $envoyes = 0;

        foreach ($tontines as $cagnotte) {
            // Nombre de cycles déjà réglés = nombre de payouts 'succes' pour cette tontine.
            $cyclesCompletes = (int) DB::table(project_table('payout'))
                ->where('cagnotte_id', $cagnotte->id)
                ->where('statut', 'succes')
                ->count();

            $prochaineDateStr = $tontineService->prochaineDate($cagnotte, $cyclesCompletes);
            if (! $prochaineDateStr) {
                // Rotation terminée ou date indéterminable — ignorer.
                continue;
            }

            $prochaineDate = now()->timezone('Africa/Libreville')
                ->startOfDay()
                ->createFromFormat('Y-m-d', $prochaineDateStr)
                ->startOfDay();

            // Différence signée : négatif = retrait déjà passé, positif = dans N jours.
            $joursRestants = (int) $today->diffInDays($prochaineDate, false);

            // On n'envoie de rappel que pour les jalons définis dans JOURS.
            if (! in_array($joursRestants, self::JOURS, true)) {
                continue;
            }

            // Membres avec compte Tondo actif qui n'ont pas encore cotisé ce cycle.
            $nonPayes = DB::table(project_table('participants'))
                ->join('users', project_table('participants').'.user_id', '=', 'users.id')
                ->where(project_table('participants').'.cagnotte_id', $cagnotte->id)
                ->whereIn(project_table('participants').'.statut_paiement', ['en_attente', 'en_retard'])
                ->whereNotNull(project_table('participants').'.user_id') // Exclure les comptes light (pas de push).
                ->pluck('users.id')
                ->filter()
                ->values()
                ->all();

            if (empty($nonPayes)) {
                $this->line("  [{$cagnotte->reference}] Tous les membres ont cotisé — pas de rappel.");
                continue;
            }

            [$titre, $corps] = $this->messageRappel($cagnotte->titre, $joursRestants, $prochaineDateStr);

            $this->line(sprintf(
                '  [%s] « %s » J%s — %d rappel(s) à envoyer',
                $cagnotte->reference,
                $cagnotte->titre,
                $joursRestants > 0 ? "-{$joursRestants}" : ($joursRestants === 0 ? '' : '+1'),
                count($nonPayes),
            ));

            if (! $isDryRun) {
                try {
                    $notif->notify(
                        userIds: $nonPayes,
                        titleFr: $titre,
                        bodyFr:  $corps,
                        data:    [
                            'type'        => 'rappel_cotisation',
                            'cagnotte_id' => $cagnotte->id,
                            'jours'       => $joursRestants,
                        ],
                    );
                    $envoyes += count($nonPayes);
                } catch (\Throwable $e) {
                    Log::error('[tontines:rappels] Échec envoi OneSignal', [
                        'cagnotte' => $cagnotte->reference,
                        'error'    => $e->getMessage(),
                    ]);
                    $this->error("  Erreur OneSignal : {$e->getMessage()}");
                }
            }
        }

        $this->info("Terminé — {$envoyes} notification(s) envoyée(s).");
        return self::SUCCESS;
    }

    /**
     * Retourne le titre et le corps de la notification selon le délai restant.
     *
     * @param  string $titreCagnotte Nom de la tontine affiché dans le message.
     * @param  int    $joursRestants Jours relatifs à la date de retrait (négatif = retard).
     * @param  string $dateStr       Date ISO 'Y-m-d' du prochain retrait.
     * @return array{string, string} [titre, corps] de la notification.
     */
    private function messageRappel(string $titreCagnotte, int $joursRestants, string $dateStr): array
    {
        return match (true) {
            $joursRestants < 0 => [
                'Cotisation en retard',
                "Votre cotisation pour « {$titreCagnotte} » n'a pas été reçue. Le retrait a eu lieu hier — cotisez dès maintenant pour ne pas pénaliser le groupe.",
            ],
            $joursRestants === 0 => [
                "C'est aujourd'hui — cotisez maintenant",
                "Le retrait de « {$titreCagnotte} » a lieu aujourd'hui. Vous n'avez pas encore cotisé — faites-le maintenant.",
            ],
            $joursRestants === 2 => [
                'Rappel — 2 jours pour cotiser',
                "Le retrait de « {$titreCagnotte} » est dans 2 jours (le {$this->formatDate($dateStr)}). Pensez à cotiser avant la date.",
            ],
            default => [
                // Cas par défaut = J-5.
                'Rappel — 5 jours pour cotiser',
                "Le prochain retrait de « {$titreCagnotte} » aura lieu le {$this->formatDate($dateStr)}. Anticipez votre cotisation.",
            ],
        };
    }

    /**
     * Formate une date ISO 'Y-m-d' en chaîne lisible francophone (ex : "3 fév 2026").
     *
     * @param  string $iso Date au format 'Y-m-d'.
     * @return string      Date formatée en français court.
     */
    private function formatDate(string $iso): string
    {
        $mois = ['jan', 'fév', 'mar', 'avr', 'mai', 'jun', 'jul', 'aoû', 'sep', 'oct', 'nov', 'déc'];
        $d    = now()->createFromFormat('Y-m-d', $iso);
        return $d->day . ' ' . $mois[$d->month - 1] . ' ' . $d->year;
    }
}
