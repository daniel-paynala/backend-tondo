<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force `Accept: application/json` sur toutes les requêtes /api/*.
 *
 * Sans ça, ValidationException (ou Auth, Throttle, etc.) renvoient une
 * redirection 302 HTML quand le client n'a pas explicitement demandé du
 * JSON dans le header Accept. Pour une API pure on veut TOUJOURS du JSON,
 * même en cas d'erreur. Flutter, curl, Postman, Next.js — tous bénéficient.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
