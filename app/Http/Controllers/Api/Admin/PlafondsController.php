<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TondoProjectConfig;
use App\Services\TondoConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plafonds TOTAUX de collecte d'une cagnotte (particulier / association).
 *
 * Stockés sur la config projet (tonji_project_config). La **lecture** est
 * ouverte à tout admin ; la **modification** est réservée aux **super_admin**.
 */
class PlafondsController extends Controller
{
    /** GET /api/admin/plafonds-cagnotte */
    public function show(Request $request): JsonResponse
    {
        $config = app(TondoConfigService::class)->getOperatorConfig(Project::tondoId());

        return response()->json([
            'plafond_cagnotte_particulier' => (int) ($config['plafond_cagnotte_particulier'] ?? 2500000),
            'plafond_cagnotte_association' => (int) ($config['plafond_cagnotte_association'] ?? 10000000),
        ]);
    }

    /** PATCH /api/admin/plafonds-cagnotte — réservé aux super_admin. */
    public function update(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->role === 'super_admin',
            403,
            'Action réservée aux super admins.',
        );

        $data = $request->validate([
            'plafond_cagnotte_particulier' => ['required', 'integer', 'min:1000', 'max:1000000000'],
            'plafond_cagnotte_association' => ['required', 'integer', 'min:1000', 'max:1000000000'],
        ]);

        // Applique à toutes les config du projet (Tonji = airtel/GA) pour rester
        // cohérent quel que soit l'opérateur.
        $maj = TondoProjectConfig::where('project_id', Project::tondoId())->update([
            'plafond_cagnotte_particulier' => $data['plafond_cagnotte_particulier'],
            'plafond_cagnotte_association' => $data['plafond_cagnotte_association'],
            'updated_at' => now(),
        ]);

        if ($maj === 0) {
            return response()->json([
                'message' => 'Aucune configuration projet à mettre à jour (config opérateur manquante).',
            ], 422);
        }

        return response()->json([
            'message'                      => 'Plafonds mis à jour.',
            'plafond_cagnotte_particulier' => $data['plafond_cagnotte_particulier'],
            'plafond_cagnotte_association' => $data['plafond_cagnotte_association'],
        ]);
    }
}
