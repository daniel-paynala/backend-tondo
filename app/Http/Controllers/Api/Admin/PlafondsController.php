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

    /**
     * GET /api/admin/frais-retrait — ce que Tonji prélève, et sur quoi.
     *
     * La matrice des frais de retrait et le taux du paiement marchand vivent
     * sur le même écran : ce sont les deux seuls taux que le dashboard règle,
     * et les séparer obligerait à chercher dans deux pages ce qui se décide
     * d'un seul mouvement.
     */
    public function showFrais(Request $request): JsonResponse
    {
        $config = app(TondoConfigService::class)->getOperatorConfig(Project::tondoId());

        return response()->json([
            'frais_retrait' => $config['frais_retrait'] ?? [
                'cagnotte' => ['particulier' => 0, 'association' => 0],
                'tontine'  => ['particulier' => 0, 'association' => 0],
            ],
            // Taux sur un paiement marchand. Zéro = aucun prélèvement, et le
            // texte des conditions le dira de lui-même.
            'frais_marchand' => (float) ($config['frais_marchand'] ?? 0),
        ]);
    }

    /** PATCH /api/admin/frais-retrait — réservé aux super_admin. Taux décimaux (0.03 = 3 %). */
    public function updateFrais(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->role === 'super_admin',
            403,
            'Action réservée aux super admins.',
        );

        $data = $request->validate([
            'frais_retrait'                      => ['required', 'array'],
            'frais_retrait.cagnotte.particulier' => ['required', 'numeric', 'min:0', 'max:0.5'],
            'frais_retrait.cagnotte.association' => ['required', 'numeric', 'min:0', 'max:0.5'],
            'frais_retrait.tontine.particulier'  => ['required', 'numeric', 'min:0', 'max:0.5'],
            'frais_retrait.tontine.association'  => ['required', 'numeric', 'min:0', 'max:0.5'],
            // Facultatif : un appel qui ne règle que la matrice reste valide.
            // Borne à 25 %, comme la commission — au-delà, c'est une erreur de
            // saisie, pas une décision commerciale.
            'frais_marchand'                     => ['sometimes', 'numeric', 'min:0', 'max:0.25'],
        ]);

        // Normalise en float et applique à la config projet.
        $matrice = [
            'cagnotte' => [
                'particulier' => (float) $data['frais_retrait']['cagnotte']['particulier'],
                'association' => (float) $data['frais_retrait']['cagnotte']['association'],
            ],
            'tontine' => [
                'particulier' => (float) $data['frais_retrait']['tontine']['particulier'],
                'association' => (float) $data['frais_retrait']['tontine']['association'],
            ],
        ];

        // Le taux marchand n'est écrit QUE s'il a été envoyé : un appel qui ne
        // règle que la matrice ne doit pas le remettre à sa valeur par défaut.
        $champs = [
            'frais_retrait' => json_encode($matrice),
            'updated_at'    => now(),
        ];
        if (array_key_exists('frais_marchand', $data)) {
            $champs['frais_marchand'] = (float) $data['frais_marchand'];
        }

        $maj = TondoProjectConfig::where('project_id', Project::tondoId())->update($champs);

        if ($maj === 0) {
            return response()->json([
                'message' => 'Aucune configuration projet à mettre à jour (config opérateur manquante).',
            ], 422);
        }

        return response()->json([
            'message'       => 'Frais de retrait mis à jour.',
            'frais_retrait' => $matrice,
        ]);
    }
}
