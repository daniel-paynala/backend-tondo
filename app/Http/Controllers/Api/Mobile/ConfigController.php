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
    public function __construct(
        private TondoConfigService $svc,
        private \App\Services\CguService $cgu,
    ) {}

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

    /**
     * GET /api/mobile/config/cgu?operateur=airtel&pays=GA
     *
     * Conditions d'utilisation rendues À PARTIR DE LA CONFIG OPÉRATEUR.
     *
     * Les chiffres (commission, plafonds, répercussion des frais de retrait)
     * etaient jusqu'ici ecrits en dur dans cinq ecrans et avaient diverge de la
     * config reellement appliquee. Ils sont desormais produits ici : une seule
     * source de verite, et le texte suit l'operateur.
     *
     * La commission apparait dans ce texte contractuel alors que `frais()` la
     * retire volontairement — la REGLE 4-bis interdit d'exposer le detail du
     * calcul dans l'UI de paiement, pas de dire le modele economique dans les
     * conditions que l'utilisateur accepte.
     */
    public function cgu(Request $request): JsonResponse
    {
        return response()->json($this->cgu->pour(
            projectId: $request->user()->project_id,
            operateur: $request->query('operateur', 'airtel'),
            pays:      $request->query('pays', 'GA'),
        ));
    }
}
