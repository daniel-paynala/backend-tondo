<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Authentification des administrateurs du dashboard Tondo.
 *
 * Flow : email + mot de passe → token Sanctum (guard `admin`).
 * Les tokens sont par device — plusieurs sessions simultanées sont supportées.
 * Un admin inactif (`actif = false`) ne peut pas se connecter.
 */
class AuthController extends Controller
{
    /**
     * POST /api/admin/login
     *
     * Authentifie un admin par email + mot de passe et retourne un token Sanctum.
     * Body : { email, password, device_name? }
     *
     * Validations clés :
     *  - email normalisé en minuscules avant la recherche
     *  - compte doit être actif (actif = true)
     *  - mot de passe vérifié via Hash::check sur password_hash
     *
     * @return JsonResponse { token: string, admin: { id, email, nom, prenom, role } }
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:64'],
        ]);

        // Recherche insensible à la casse via normalisation.
        $admin = TondoAdmin::where('email', strtolower($data['email']))->first();

        // Message d'erreur générique pour ne pas confirmer l'existence de l'email.
        if (! $admin || ! $admin->actif || ! Hash::check($data['password'], $admin->password_hash)) {
            throw ValidationException::withMessages([
                'email' => 'Identifiants invalides.',
            ]);
        }

        // Horodatage de la dernière connexion (audit).
        $admin->forceFill(['derniere_connexion' => now()])->save();

        $deviceName = $data['device_name'] ?? 'dashboard-web';
        // createToken génère un token Sanctum lié à ce device.
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
     *
     * Retourne le profil de l'admin authentifié par le token Sanctum courant.
     * Utilisé par le dashboard pour vérifier la session et obtenir le rôle.
     *
     * @return JsonResponse { id, email, nom, prenom, role, derniere_connexion }
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
            // Préférences d'e-mails système (fusionnées avec les défauts).
            'notif_prefs' => $admin->notifPrefs(),
        ]);
    }

    /**
     * PATCH /api/admin/me/notifications
     *
     * Met à jour les préférences d'e-mails système de l'admin authentifié
     * (signalements, problèmes techniques, autres événements).
     *
     * Body : { signalements: bool, problemes: bool, autre: bool }
     */
    public function updateNotifications(Request $request): JsonResponse
    {
        $data = $request->validate([
            'signalements' => ['required', 'boolean'],
            'problemes'    => ['required', 'boolean'],
            'autre'        => ['required', 'boolean'],
        ]);

        $admin = $request->user();
        $admin->notif_prefs = [
            'signalements' => $data['signalements'],
            'problemes'    => $data['problemes'],
            'autre'        => $data['autre'],
        ];
        $admin->save();

        return response()->json(['ok' => true, 'notif_prefs' => $admin->notifPrefs()]);
    }

    /**
     * POST /api/admin/logout
     *
     * Révoque le token Sanctum du device courant (déconnexion partielle).
     * Les autres tokens actifs (autres devices) ne sont pas affectés.
     *
     * @return JsonResponse { ok: true }
     */
    public function logout(Request $request): JsonResponse
    {
        // Supprime uniquement le token de ce device.
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * PATCH /api/admin/me/password
     *
     * Permet à l'admin authentifié de changer son propre mot de passe
     * (notamment après réception d'un mot de passe provisoire par e-mail).
     * Vérifie le mot de passe actuel avant d'appliquer le nouveau.
     *
     * Body : { current_password, new_password }
     *
     * @return JsonResponse { ok: true } ou 422 si le mot de passe actuel est faux.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'different:current_password'],
        ]);

        $admin = $request->user();

        if (! Hash::check($data['current_password'], $admin->password_hash)) {
            return response()->json(['message' => 'Mot de passe actuel incorrect.'], 422);
        }

        $admin->password_hash = Hash::make($data['new_password']);
        $admin->save();

        return response()->json(['ok' => true]);
    }
}
