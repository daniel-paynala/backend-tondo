<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TondoDeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Enregistrement des jetons push (FCM) des appareils, côté mobile.
 *
 * L'app appelle `store` juste après la connexion (avec son registration token
 * FCM), et `destroy` à la déconnexion. Le backend s'en sert ensuite pour cibler
 * les pushs ({@see \App\Services\FcmService}).
 *
 * Un token est unique par projet : s'il existe déjà (même appareil), on se
 * contente de réaffecter le `user_id` (dernier utilisateur connecté) et la
 * plateforme.
 */
class DeviceTokensController extends Controller
{
    /**
     * POST /api/mobile/devices
     * Body : { token: string, plateforme: 'android'|'ios' }
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'      => ['required', 'string', 'max:4096'],
            'plateforme' => ['required', 'in:android,ios'],
        ]);

        $user = $request->user();

        // Upsert par (projet, token) : réaffecte l'appareil au user courant.
        $ligne = TondoDeviceToken::query()->firstOrNew([
            'project_id' => $user->project_id,
            'token'      => $data['token'],
        ]);

        // id généré en PHP à la création (le DEFAULT gen_random_uuid() Postgres
        // ne renvoie pas la valeur à Eloquent).
        if (! $ligne->exists) {
            $ligne->id = (string) Str::uuid();
        }

        $ligne->user_id    = $user->id;
        $ligne->plateforme = $data['plateforme'];
        $ligne->save();

        return response()->json(['message' => 'Appareil enregistré.']);
    }

    /**
     * DELETE /api/mobile/devices
     * Body : { token: string }
     *
     * Délie l'appareil (déconnexion) : il ne recevra plus les pushs de ce compte.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
        ]);

        TondoDeviceToken::query()
            ->where('project_id', $request->user()->project_id)
            ->where('token', $data['token'])
            ->delete();

        return response()->json(['message' => 'Appareil retiré.']);
    }
}
