<?php

use App\Console\Commands\TontineRappelsCommand;
use App\Console\Commands\TraiterRetraitsTontines;
use App\Console\Commands\TraiterReversementsAutoCagnottes;
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
 * Reversements automatiques cotisations ouvertes — 08h heure de Libreville.
 * Vérifie chaque jour si une échéance (date, montant cible) est atteinte
 * ou si le délai de fréquence libre (N mois) est écoulé.
 */
Schedule::command(TraiterReversementsAutoCagnottes::class)
    ->dailyAt('08:00')
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
