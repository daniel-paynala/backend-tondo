<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Stub pour les routes mobile. Les vraies implémentations passeront par
 * un middleware Supabase JWT (à coder) puis des controllers dédiés :
 *  - Api\Mobile\AuthController
 *  - Api\Mobile\CagnottesController
 *  - Api\Mobile\CotisationsController
 *  - Api\Mobile\ProfilController
 *
 * En attendant, tous les endpoints retournent 501 Not Implemented avec
 * un message explicite, ce qui permet de cabler les routes dans Postman
 * dès maintenant.
 */
class StubController extends Controller
{
    /**
     * Retourne une réponse 501 Not Implemented pour un endpoint non encore codé.
     *
     * @param string $what Libellé lisible de l'endpoint (ex : 'profil', 'cotisations')
     * @return JsonResponse { status: 'not_implemented', message: string } (501)
     */
    public function notImplemented(string $what = 'endpoint'): JsonResponse
    {
        return response()->json([
            'status' => 'not_implemented',
            'message' => "L'endpoint mobile « {$what} » n'est pas encore implémenté. À brancher après le câblage du middleware Supabase JWT.",
        ], 501);
    }
}
