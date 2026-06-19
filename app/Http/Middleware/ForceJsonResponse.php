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
    /**
     * Injecte l'en-tête `Accept: application/json` avant de passer la requête
     * au reste de la pile de middlewares et au contrôleur.
     *
     * Résultat : ValidationException, AuthenticationException, ThrottleRequests,
     * et toutes les autres exceptions Laravel renvoient du JSON au lieu d'un
     * redirect HTML 302 — comportement souhaité pour une API pure (Flutter, curl, etc.).
     *
     * @param  Request  $request La requête HTTP entrante.
     * @param  Closure  $next    Le prochain middleware dans la pile.
     * @return Response          La réponse HTTP (toujours JSON grâce à cet en-tête).
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Forcer Accept: application/json sur toutes les requêtes /api/*.
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
