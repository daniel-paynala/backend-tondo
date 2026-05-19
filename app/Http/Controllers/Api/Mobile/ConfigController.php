<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\TondoConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Config dynamique exposée au mobile.
 *
 * Permet au client Flutter de récupérer les paramètres tarifaires pilotés
 * serveur par opérateur / pays — un changement de taux ne nécessite aucune
 * mise à jour de l'app sur les stores.
 *
 * La commission Paynala est exclue de la réponse (backend-only).
 */
class ConfigController extends Controller
{
    public function __construct(private TondoConfigService $svc) {}

    /**
     * GET /api/mobile/config/frais?operateur=airtel&pays=GA
     *
     * Retourne la grille tarifaire de transfert pour l'opérateur/pays demandé.
     * Commission Paynala exclue — appliquée côté serveur uniquement.
     */
    public function frais(Request $request): JsonResponse
    {
        $operateur = $request->query('operateur', 'airtel');
        $pays      = $request->query('pays', 'GA');
        $projectId = $request->user()->project_id;

        $cfg = $this->svc->getOperatorConfig($projectId, $operateur, $pays);

        unset($cfg['commission_paynala']);

        return response()->json($cfg);
    }
}
