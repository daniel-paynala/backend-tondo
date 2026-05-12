<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TondoUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Auth mobile — flow OTP statique en mode test (123456).
 *
 * Quand on branchera Supabase Auth phone OTP en prod, ces deux endpoints
 * disparaissent : Flutter parlera directement à Supabase, le trigger
 * `on_auth_user_created` créera la row public.users, et le middleware
 * `verify.supabase.jwt` (à coder) hydratera $request->user() à partir
 * du JWT Supabase. Le reste des controllers mobile reste inchangé.
 */
class AuthController extends Controller
{
    /** OTP statique acceptée en mode test. */
    private const OTP_DEV = '123456';

    /**
     * POST /api/mobile/auth/request-otp
     * Body : { indicatif: "+241", numero: "77123456" }
     *
     * En dev : log et retourne ok. Plus tard : appelle Supabase Auth
     * `signInWithOtp({ phone })` qui déclenche le SMS.
     */
    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'indicatif' => ['required', 'string', 'regex:/^\+?\d{1,4}$/'],
            'numero' => ['required', 'string', 'regex:/^\d{6,12}$/'],
        ]);

        $phone = $this->formatPhone($data['indicatif'], $data['numero']);

        Log::info("[mobile] request-otp pour {$phone} — OTP dev statique 123456");

        return response()->json([
            'ok' => true,
            'message' => 'OTP envoyé.',
            'phone' => $phone,
            // En dev on retourne explicitement l'OTP pour faciliter les tests
            // Postman / Flutter. À RETIRER en prod (et basculer sur SMS réel).
            'dev_hint' => app()->environment('local', 'testing') ? self::OTP_DEV : null,
        ]);
    }

    /**
     * POST /api/mobile/auth/verify-otp
     * Body : {
     *   indicatif, numero, otp,
     *   nom?, prenom?, date_naissance?, type_client?   (requis si sign-up)
     * }
     *
     * Si user existe pour ce numéro → connexion, retourne token + user.
     * Sinon : exige nom/prenom/date_naissance et crée le user.
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'indicatif' => ['required', 'string', 'regex:/^\+?\d{1,4}$/'],
            'numero' => ['required', 'string', 'regex:/^\d{6,12}$/'],
            'otp' => ['required', 'string', 'size:6'],
            'nom' => ['nullable', 'string', 'max:80'],
            'prenom' => ['nullable', 'string', 'max:80'],
            'date_naissance' => ['nullable', 'date', 'before:today'],
            'type_client' => ['nullable', 'in:particulier,entreprise,marchand'],
            'device_name' => ['nullable', 'string', 'max:64'],
        ]);

        if ($data['otp'] !== self::OTP_DEV) {
            throw ValidationException::withMessages([
                'otp' => 'Code OTP invalide.',
            ]);
        }

        $phone = $this->formatPhone($data['indicatif'], $data['numero']);
        $projectId = Project::tondoId();

        $user = TondoUser::where('project_id', $projectId)
            ->where('numero', $phone)
            ->first();

        if (! $user) {
            // Sign-up : on exige les 3 champs minimaux (RÈGLE 4-bis).
            $missing = [];
            foreach (['nom', 'prenom', 'date_naissance'] as $f) {
                if (empty($data[$f])) {
                    $missing[$f] = "Le champ {$f} est requis pour la création du compte.";
                }
            }
            if (! empty($missing)) {
                throw ValidationException::withMessages($missing);
            }

            $user = new TondoUser();
            $user->id = (string) Str::uuid();
            $user->project_id = $projectId;
            $user->nom = $data['nom'];
            $user->prenom = $data['prenom'];
            $user->date_naissance = $data['date_naissance'];
            $user->numero = $phone;
            $user->type_client = $data['type_client'] ?? 'particulier';
            $user->kyc_valide = false; // KYC opérateur fait plus tard
            $user->save();

            $created = true;
        } else {
            $created = false;
        }

        $deviceName = $data['device_name'] ?? 'mobile-app';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'created' => $created,
            'user' => $this->serializeUser($user),
        ]);
    }

    /**
     * GET /api/mobile/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->serializeUser($request->user()),
        ]);
    }

    /**
     * POST /api/mobile/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Formate indicatif + numéro en E.164 : "+241XXXXXXXX". On stocke
     * cette forme dans la colonne `numero` (sans espaces, sans tiret).
     */
    private function formatPhone(string $indicatif, string $numero): string
    {
        $ind = ltrim($indicatif, '+');
        $num = ltrim($numero, '0'); // supprime le 0 leading du format local

        return '+' . $ind . $num;
    }

    private function serializeUser(TondoUser $user): array
    {
        return [
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
        ];
    }
}
