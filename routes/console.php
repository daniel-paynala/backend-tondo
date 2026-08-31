<?php

use App\Console\Commands\CleanReceiptsCommand;
use App\Console\Commands\ResumeQuotidienCommand;
use App\Console\Commands\TontineRappelsCommand;
use App\Console\Commands\TraiterRetraitsTontines;
use App\Console\Commands\TraiterReversementsAutoCagnottes;
use App\Console\Commands\VerifierPaiementsEnAttenteCommand;
use App\Console\Commands\ReconcilierPayinsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Retrait automatique des tontines périodiques — 20h heure de Libreville
 * (Africa/Libreville = UTC+1).
 *
 * Prérequis sur le serveur AWS :
 *   crontab -e
 *   * * * * * php /var/www/html/artisan schedule:run >> /dev/null 2>&1
 */
/*
 * Retrait automatique des tontines périodiques — 20h heure de Libreville.
 */
Schedule::command(TraiterRetraitsTontines::class)
    ->dailyAt('20:00')
    ->timezone('Africa/Libreville')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Reversements automatiques cotisations ouvertes — 18h heure de Libreville.
 * Modes : date atteinte, montant cible atteint, fréquence libre (N mois),
 * ou reversement systématique quotidien (solde > 0, sans échéance configurée).
 */
Schedule::command(TraiterReversementsAutoCagnottes::class)
    ->dailyAt('18:00')
    ->timezone('Africa/Libreville')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Rappels de cotisation — 09h heure de Libreville.
 * Envoie des notifications aux membres qui n'ont pas cotisé à :
 *   J-5, J-2, J (jour du retrait), J+1 (retard).
 */
Schedule::command(TontineRappelsCommand::class)
    ->dailyAt('09:00')
    ->timezone('Africa/Libreville')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Vérification des paiements WhatsApp en attente — toutes les 5 secondes.
 * Remplace VerifierPaiementJob (ne nécessite pas de queue worker).
 * No-op instantané si aucun paiement en attente.
 */
Schedule::command(VerifierPaiementsEnAttenteCommand::class)
    ->everyFiveSeconds()
    ->withoutOverlapping();

/*
 * Réconciliation des payin restés 'initie' (confirmations Airtel TARDIVES non
 * captées par le polling app ou par le timeout 3 min du cron WhatsApp) —
 * toutes les 5 min. Crédite les paiements réellement confirmés côté agrégateur.
 * Idempotent (claim atomique + index unique trans_id).
 */
Schedule::command(ReconcilierPayinsCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

/*
 * Nettoyage des PDFs de receipts/ vieux de plus de 24h — 02h chaque nuit.
 */
Schedule::command(CleanReceiptsCommand::class)
    ->dailyAt('02:00')
    ->timezone('Africa/Libreville')
    ->withoutOverlapping();

/*
 * Résumé quotidien du soir aux créateurs de cagnottes — 20h heure de Libreville.
 * N'envoie qu'aux créateurs dont AU MOINS UNE cagnotte a reçu une cotisation
 * aujourd'hui (« seulement s'il y a eu du mouvement »). Chaque envoi est un
 * template payant (UTILITY) : au plus 1/jour/créateur actif. Ne fait rien tant
 * que services.whatsapp.templates.resume_quotidien n'est pas renseigné.
 */
Schedule::command(ResumeQuotidienCommand::class)
    ->dailyAt('20:00')
    ->timezone('Africa/Libreville')
    ->withoutOverlapping()
    ->runInBackground();
