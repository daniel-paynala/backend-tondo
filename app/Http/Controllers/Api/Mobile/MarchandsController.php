<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\CarnetMarchands;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Marchands proposés au client au moment d'un transfert.
 *
 * Enveloppe HTTP de {@see CarnetMarchands}, qui porte la lecture elle-même et
 * la partage avec le bot WhatsApp. Ce contrôleur ne décide de rien : il valide
 * la requête et met en forme la réponse.
 */
class MarchandsController extends Controller
{
    public function __construct(private readonly CarnetMarchands $carnet) {}

    /**
     * GET /api/mobile/marchands
     * Paramètre facultatif : q (recherche sur le nom).
     */
    public function index(Request $request): JsonResponse
    {
        $marchands = $this->carnet->actifs(
            $request->user()->project_id,
            $request->input('q'),
        );

        return response()->json([
            'marchands' => $marchands,
            // Le client range la liste par catégorie ; l'ordre vient d'ici pour
            // que l'app n'ait pas à le deviner.
            'categories' => $marchands->pluck('categorie')->filter()->unique()->sort()->values(),
        ]);
    }

    /**
     * GET /api/mobile/marchands/resoudre?saisie=...
     *
     * Le client tape dans un seul champ soit le numéro de l'enseigne, soit son
     * code. Plusieurs réponses sont normales — voir
     * {@see CarnetMarchands::resoudre()}.
     */
    public function resoudre(Request $request): JsonResponse
    {
        $data = $request->validate([
            'saisie' => ['required', 'string', 'max:32'],
        ]);

        return response()->json([
            'marchands' => $this->carnet->resoudre(
                $request->user()->project_id,
                $data['saisie'],
            ),
        ]);
    }
}
