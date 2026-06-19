<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Fournisseur de services principal de l'application Tondo.
 *
 * Responsabilités typiques de ce provider :
 *  – `register()` : lier des interfaces à leurs implémentations dans le container IoC.
 *  – `boot()`     : bootstrapper des comportements globaux (macros, observers, gates…).
 *
 * Actuellement vide — les bindings spécifiques Tondo sont répartis dans
 * des providers dédiés (ex : PaymentServiceProvider, WhatsAppServiceProvider)
 * ou gérés par l'auto-discovery Laravel.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Enregistre les liaisons dans le container IoC.
     *
     * Appelé avant boot() — à utiliser pour les bindings dont d'autres providers
     * pourraient dépendre au démarrage.
     */
    public function register(): void
    {
        //
    }

    /**
     * Initialise les services après que tous les providers ont été enregistrés.
     *
     * Bon endroit pour : model observers, gate definitions, macro Blade,
     * configuration de la queue, validation rules personnalisées, etc.
     */
    public function boot(): void
    {
        //
    }
}
