<?php

namespace App\Http\Middleware;

use App\Models\TondoPartenaireRetrait;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Première moitié de l'authentification d'un terminal : le système du
 * partenaire présente sa clé d'API dans l'en-tête `X-Cle-Partenaire`.
 *
 * Exigée sur TOUTES les routes d'agent, connexion comprise. C'est ce qui rend
 * un PIN volé inutile hors d'un terminal du partenaire : sans la clé, même le
 * bon identifiant et le bon PIN n'ouvrent rien.
 *
 * Le partenaire résolu est posé dans les attributs de la requête, pour que les
 * contrôles suivants vérifient que l'agent lui appartient bien.
 */
class AuthentifiePartenaire
{
    public const EN_TETE = 'X-Cle-Partenaire';

    public function handle(Request $request, Closure $next): Response
    {
        $cle = trim((string) $request->header(self::EN_TETE));

        // Même réponse pour une clé absente et une clé inconnue : rien ne doit
        // aider à distinguer un format correct d'une clé valide.
        $partenaire = $cle !== '' ? TondoPartenaireRetrait::parCleApi($cle) : null;
        if (! $partenaire) {
            return response()->json(['message' => 'Clé partenaire absente ou invalide.'], 401);
        }

        // Un partenaire désactivé coupe tous ses terminaux à l'appel suivant.
        if (! $partenaire->actif) {
            return response()->json(['message' => 'Partenaire désactivé.'], 403);
        }

        $request->attributes->set('partenaire', $partenaire);

        return $next($request);
    }
}
