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
        // L'API est servie derrière le proxy Cloudflare (api.tonji.ga) : le TLS
        // est terminé au bord, et l'origine reçoit la requête en HTTP clair.
        // Sans cette ligne, Laravel croit que le site tourne en HTTP et génère
        // toutes ses URLs absolues en `http://` — notamment, dans ReceiptService,
        // le lien du reçu PDF et l'URL encodée dans le QR code, qui seraient
        // alors bloqués par les navigateurs et les clients mobiles.
        // Faire confiance à l'en-tête X-Forwarded-Proto envoyé par Cloudflare
        // corrige le schéma généré.
        //
        // ⚠️ `at: '*'` fait confiance à n'importe quel proxy. C'est sûr tant que
        // l'origine n'est joignable QUE par Cloudflare : verrouiller le groupe de
        // sécurité AWS sur les plages d'IP Cloudflare (cloudflare.com/ips), sinon
        // un appel direct à l'IP publique pourrait usurper le schéma.
        $middleware->trustProxies(at: '*');

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
