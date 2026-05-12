<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/admin/login
     * Body: { email, password, device_name? }
     * Response: { token, admin }
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:64'],
        ]);

        $admin = TondoAdmin::where('email', strtolower($data['email']))->first();

        if (! $admin || ! $admin->actif || ! Hash::check($data['password'], $admin->password_hash)) {
            throw ValidationException::withMessages([
                'email' => 'Identifiants invalides.',
            ]);
        }

        $admin->forceFill(['derniere_connexion' => now()])->save();

        $deviceName = $data['device_name'] ?? 'dashboard-web';
        $token = $admin->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'admin' => [
                'id' => $admin->id,
                'email' => $admin->email,
                'nom' => $admin->nom,
                'prenom' => $admin->prenom,
                'role' => $admin->role,
            ],
        ]);
    }

    /**
     * GET /api/admin/me
     * Renvoie l'admin courant si le token est valide.
     */
    public function me(Request $request): JsonResponse
    {
        $admin = $request->user();

        return response()->json([
            'id' => $admin->id,
            'email' => $admin->email,
            'nom' => $admin->nom,
            'prenom' => $admin->prenom,
            'role' => $admin->role,
            'derniere_connexion' => $admin->derniere_connexion,
        ]);
    }

    /**
     * POST /api/admin/logout
     * Révoque le token courant (logout du device).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }
}
