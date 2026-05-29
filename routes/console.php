<?php

use App\Console\Commands\TraiterRetraitsTontines;
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
Schedule::command(TraiterRetraitsTontines::class)
    ->dailyAt('20:00')
    ->timezone('Africa/Libreville')
    ->withoutOverlapping()
    ->runInBackground();
