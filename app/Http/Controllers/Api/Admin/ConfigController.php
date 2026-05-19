<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\TondoConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestion de la configuration tarifaire depuis le back-office admin.
 *
 * Lecture : fusionne ligne DB + fallback config/airtel.php.
 * Écriture : persiste dans `tondo_project_config` (une ligne par projet).
 */
class ConfigController extends Controller
{
    public function __construct(private TondoConfigService $configService) {}

    /**
     * GET /api/admin/config
     */
    public function index(Request $request): JsonResponse
    {
        $projectId = $request->user()->project_id;
        $airtel    = $this->configService->getAirtelConfig($projectId);

        return response()->json([
            'airtel' => [
                'commission_paynala'  => (float) $airtel['commission_paynala'],
                'plafond_par_envoi'   => (int)   $airtel['plafond_par_envoi'],
                'plafond_journalier'  => (int)   $airtel['plafond_journalier'],
                'retrait' => [
                    'seuil_tranche'    => (int)   $airtel['retrait']['seuil_tranche'],
                    'taux_pourcentage' => (float) $airtel['retrait']['taux_pourcentage'],
                    'forfait'          => (int)   $airtel['retrait']['forfait'],
                ],
            ],
            'cagnotte' => [
                'montant_min'             => 100,
                'plafond_par_transaction' => (int) $airtel['plafond_par_envoi'],
                'plafond_journalier'      => (int) $airtel['plafond_journalier'],
                'reference_longueur'      => '4-5 chiffres',
                'types_supportes'         => ['tontine_periodique', 'cagnotte_ouverte'],
            ],
        ]);
    }

    /**
     * PATCH /api/admin/config
     *
     * Valide et persiste les nouveaux taux. Les bornes évitent les erreurs
     * de saisie grossières (ex : confusion % vs décimal).
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'commission_paynala'       => ['required', 'numeric', 'min:0.001', 'max:0.25'],
            'plafond_par_envoi'        => ['required', 'integer', 'min:50000', 'max:5000000'],
            'plafond_journalier'       => ['required', 'integer', 'min:100000', 'max:50000000'],
            'retrait.seuil_tranche'    => ['required', 'integer', 'min:10000', 'max:2000000'],
            'retrait.taux_pourcentage' => ['required', 'numeric', 'min:0.001', 'max:0.25'],
            'retrait.forfait'          => ['required', 'integer', 'min:100', 'max:100000'],
        ]);

        $projectId = $request->user()->project_id;
        $this->configService->updateAirtelConfig($projectId, $data);

        // Re-lire pour confirmer la valeur persistée.
        return $this->index($request);
    }
}
