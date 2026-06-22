<?php

use App\Http\Controllers\ReceiptViewController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Routes stateless sans aucun middleware (pas de session, pas d'auth).
        // Définies ici plutôt que dans web.php pour éviter le groupe "web"
        // qui inclut StartSession + ShareErrorsFromSession → erreur si table
        // "sessions" absente (cas Supabase sans migration sessions).
        then: function () {
            Route::middleware([])
                ->prefix('recu')
                ->group(function () {
                    Route::get('/{transId}',     [ReceiptViewController::class, 'show']);
                    Route::get('/{transId}/pdf', [ReceiptViewController::class, 'pdf']);
                });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Tout endpoint /api/* doit toujours répondre en JSON, même en
        // cas d'erreur (validation, auth, throttle). Sans ce middleware
        // Laravel redirige (302) sur ValidationException quand le client
        // n'a pas envoyé Accept: application/json.
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
