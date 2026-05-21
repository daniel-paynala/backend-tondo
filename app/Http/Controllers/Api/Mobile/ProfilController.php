<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\TondoConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestion du profil mobile. Les champs collectés à l'inscription
 * (nom, prenom, date_naissance, numero) ne sont PAS modifiables ici —
 * c'est une règle métier (anti-fraude + KYC opérateur déjà fait sur
 * le numéro). Sont modifiables : les champs "différés" (sexe, adresse,
 * email) demandés au moment où c'est nécessaire (RÈGLE 4-bis).
 */
class ProfilController extends Controller
{
    /**
     * GET /api/mobile/profil
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'nom' => $user->nom,
                'prenom' => $user->prenom,
                'numero' => $user->numero,
                'date_naissance' => $user->date_naissance?->toDateString(),
                'type_client' => $user->type_client,
                'kyc_valide' => $user->kyc_valide,
                'sexe' => $user->sexe,
                'adresse' => $user->adresse,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * GET /api/mobile/users/lookup?numero=+24177xxxxxx
     *
     * Vérifie si un numéro est enregistré dans Tondo.
     * Renvoie { found: true, nom, prenom } ou { found: false }.
     * Utilisé par l'écran d'ajout de participants pour éviter la saisie manuelle.
     */
    public function lookup(Request $request): JsonResponse
    {
        $numero    = preg_replace('/[\s\-]/', '', $request->string('numero')->toString());
        $projectId = $request->user()->project_id;

        $utilisateur = \App\Models\TondoUser::where('project_id', $projectId)
            ->where('numero', $numero)
            ->first();

        $opInfo = app(TondoConfigService::class)->detectOperateur($numero, $projectId);

        if (! $utilisateur) {
            return response()->json([
                'found'          => false,
                'operateur'      => $opInfo['operateur'],
                'operateur_logo' => $opInfo['operateur_logo'],
            ]);
        }

        return response()->json([
            'found'          => true,
            'nom'            => $utilisateur->nom,
            'prenom'         => $utilisateur->prenom,
            'operateur'      => $opInfo['operateur'],
            'operateur_logo' => $opInfo['operateur_logo'],
        ]);
    }

    /**
     * PATCH /api/mobile/profil
     * Body : { sexe?, adresse?, email? }
     *
     * Seuls les champs différés sont éditables.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sexe' => ['nullable', 'in:homme,femme'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $user = $request->user();
        $user->fill($data)->save();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'nom' => $user->nom,
                'prenom' => $user->prenom,
                'numero' => $user->numero,
                'date_naissance' => $user->date_naissance?->toDateString(),
                'type_client' => $user->type_client,
                'kyc_valide' => $user->kyc_valide,
                'sexe' => $user->sexe,
                'adresse' => $user->adresse,
                'email' => $user->email,
            ],
        ]);
    }
}
