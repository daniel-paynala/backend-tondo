<?php

use App\Console\Commands\CleanReceiptsCommand;
use App\Console\Commands\TontineRappelsCommand;
use App\Console\Commands\TraiterRetraitsTontines;
use App\Console\Commands\TraiterReversementsAutoCagnottes;
use App\Console\Commands\VerifierPaiementsEnAttenteCommand;
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
 * Envoie des notifications aux participants qui n'ont pas cotisé à :
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
 * Nettoyage des PDFs de receipts/ vieux de plus de 24h — 02h chaque nuit.
 */
Schedule::command(CleanReceiptsCommand::class)
    ->dailyAt('02:00')
    ->timezone('Africa/Libreville')
    ->withoutOverlapping();
