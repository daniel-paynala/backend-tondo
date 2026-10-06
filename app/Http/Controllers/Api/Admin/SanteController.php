<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\Sante;
use Illuminate\Http\JsonResponse;

/**
 * État du système pour le back-office.
 *
 * Même service que la commande `tonji:sante` : les règles ne sont écrites
 * qu'une fois, la console et le dashboard ne peuvent pas diverger.
 *
 * Lecture ouverte à tous les rôles d'administration : constater une panne ne
 * demande pas de privilège particulier, et une alerte vue tard est pire qu'une
 * alerte vue par quelqu'un qui n'a pas la main pour corriger.
 */
class SanteController extends Controller
{
    /** GET /api/admin/sante */
    public function index(Sante $sante): JsonResponse
    {
        return response()->json($sante->rapport());
    }
}
