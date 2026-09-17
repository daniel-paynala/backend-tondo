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

        // Terminaux des agents de retrait : clé du partenaire, puis contrôle de
        // l'agent à chaque appel.
        $middleware->alias([
            'partenaire'         => \App\Http\Middleware\AuthentifiePartenaire::class,
            'agent.operationnel' => \App\Http\Middleware\AgentOperationnel::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API des agents de retrait : chaque refus porte un `code` stable, que
        // le logiciel du partenaire lit sans analyser le texte — y compris les
        // erreurs levées par le framework lui-même, qui ne le fourniraient pas
        // (« Unauthenticated. », « Too Many Attempts. »).
        $agent = fn (\Illuminate\Http\Request $r) => $r->is('api/agent/*');

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $r) use ($agent) {
            return $agent($r) ? response()->json([
                'message' => 'Session expirée ou invalide. Reconnectez-vous.',
                'code'    => 'session_invalide',
            ], 401) : null;
        });

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, \Illuminate\Http\Request $r) use ($agent) {
            return $agent($r) ? response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Requête invalide.',
                'code'    => 'requete_invalide',
                'erreurs' => $e->errors(),
            ], 422) : null;
        });

        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, \Illuminate\Http\Request $r) use ($agent) {
            return $agent($r) ? response()->json([
                'message' => 'Trop de requêtes. Réessayez dans un instant.',
                'code'    => 'trop_de_requetes',
            ], 429, $e->getHeaders()) : null;
        });
    })->create();
