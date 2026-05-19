<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Exposition de la configuration système aux admins.
 *
 * Lecture seule pour l'instant — les taux sont pilotés par les variables
 * d'environnement (config/airtel.php). Une table `tondo_project_config`
 * sera ajoutée quand le dashboard doit pouvoir les modifier en live.
 */
class ConfigController extends Controller
{
    /**
     * GET /api/admin/config
     *
     * Retourne la grille tarifaire complète + paramètres de définition
     * d'une cagnotte. Consommé par la page Paramètres du back-office.
     */
    public function index(): JsonResponse
    {
        $airtel = config('airtel');

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
                'montant_min'          => 100,
                'plafond_par_transaction' => (int) $airtel['plafond_par_envoi'],
                'plafond_journalier'   => (int) $airtel['plafond_journalier'],
                'reference_longueur'   => '4-5 chiffres',
                'types_supportes'      => ['tontine_periodique', 'cagnotte_ouverte'],
            ],
        ]);
    }
}
