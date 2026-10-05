<?php

namespace App\Http\Middleware;

use App\Support\JetonMarchand;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un jeton de portail marchand valide et renseigne la requête.
 *
 * Le numéro vient du JETON, jamais d'un paramètre : s'il était fourni par
 * l'appelant, il suffirait d'en changer pour lire le suivi d'un autre
 * commerce. C'est le seul endroit où cette garantie est posée.
 */
class JetonMarchandRequis
{
    public function handle(Request $request, Closure $next): Response
    {
        $charge = JetonMarchand::lire($request->bearerToken());

        if ($charge === null) {
            return response()->json([
                'message' => 'Session expirée. Demandez un nouveau code.',
                'code'    => 'session_expiree',
            ], 401);
        }

        $request->attributes->set('marchand_numero', $charge['numero']);
        $request->attributes->set('marchand_projet', $charge['projet']);

        return $next($request);
    }
}
