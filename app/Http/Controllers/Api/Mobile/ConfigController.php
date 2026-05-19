<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Config dynamique exposée au mobile.
 *
 * Permet au client Flutter de récupérer des paramètres pilotés serveur
 * (et à terme par le dashboard admin) au lieu de les coder en dur dans
 * l'app — un changement de taux ne nécessite alors aucune mise à jour
 * de l'app sur les stores.
 */
class ConfigController extends Controller
{
    /**
     * GET /api/mobile/config/frais
     *
     * Renvoie la grille tarifaire (commission Paynala + frais de retrait
     * Airtel) utilisée par le mobile pour calculer l'aperçu des frais à la
     * création d'une cagnotte. Source : `config/airtel.php`.
     */
    public function frais(): JsonResponse
    {
        $airtel = config('airtel');

        return response()->json([
            'commission_paynala' => (float) $airtel['commission_paynala'],
            'plafond_par_envoi' => (int) $airtel['plafond_par_envoi'],
            'plafond_journalier' => (int) $airtel['plafond_journalier'],
            'retrait' => [
                'seuil_tranche' => (int) $airtel['retrait']['seuil_tranche'],
                'taux_pourcentage' => (float) $airtel['retrait']['taux_pourcentage'],
                'forfait' => (int) $airtel['retrait']['forfait'],
            ],
        ]);
    }
}
