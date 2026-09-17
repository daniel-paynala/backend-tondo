<?php

namespace App\Http\Middleware;

use App\Models\TondoAgent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seconde moitié : l'agent authentifié par son jeton peut-il opérer MAINTENANT ?
 *
 * Vérifié à chaque requête, sans cache, pour qu'une suspension, un verrou ou la
 * désactivation d'un partenaire ou d'un support prenne effet à l'appel suivant
 * et non à l'expiration de la session.
 *
 * Avec le paramètre `pin`, le PIN initial non encore changé est toléré : c'est
 * la seule route qui doit rester accessible dans cet état.
 */
class AgentOperationnel
{
    public function handle(Request $request, Closure $next, ?string $exception = null): Response
    {
        /** @var TondoAgent|null $agent */
        $agent      = $request->user('agent');
        $partenaire = $request->attributes->get('partenaire');

        // Le jeton doit appartenir à un agent DU partenaire qui présente sa clé :
        // la clé d'Ecobank ne doit pas servir à piloter un agent d'un autre.
        if (! $agent || ! $partenaire || $agent->partenaire_id !== $partenaire->id) {
            return response()->json(['message' => 'Session d\'agent invalide pour ce partenaire.'], 401);
        }

        $agent->loadMissing(['partenaire', 'support']);

        if ($motif = $agent->motifBlocage()) {
            return response()->json(['message' => $motif, 'code' => 'agent_bloque'], 403);
        }

        if ($agent->pin_doit_changer && $exception !== 'pin') {
            return response()->json([
                'message' => 'Changez votre PIN avant de continuer.',
                'code'    => 'pin_a_changer',
            ], 403);
        }

        // Écriture directe, sans passer par save() : on ne veut ni déclencher
        // updated_at sur chaque appel, ni réécrire les autres colonnes.
        TondoAgent::whereKey($agent->id)->update(['derniere_activite_at' => now()]);

        return $next($request);
    }
}
