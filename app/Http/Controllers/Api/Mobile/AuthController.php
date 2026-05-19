<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TondoUser;
use App\Services\OperateurDetectorService;
use App\Services\TwilioVerifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Auth mobile — flow OTP avec deux drivers (config `services.otp.driver`) :
 *  - `dev`    : OTP statique 123456 accepté tel quel. Gratuit. dev_hint
 *               renvoyé dans la réponse pour faciliter les tests.
 *  - `twilio` : Twilio Verify — SMS réel via l'API. Code généré et géré
 *               côté Twilio (rate-limit + expiration auto).
 */
class AuthController extends Controller
{
    /** OTP statique accepté en mode `dev`. */
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
            // `login`  : depuis Welcome → "Continuer". On envoie l'OTP
            //            uniquement si l'user existe ; sinon on rend
            //            user_exists:false pour que l'app affiche la
            //            modale "non inscrit" sans gaspiller un SMS.
            // `signup` : depuis SignUp → "Recevoir le code". On envoie
            //            toujours l'OTP, c'est explicitement une création
            //            de compte.
            'intent' => ['nullable', 'in:login,signup'],
        ]);

        $intent = $data['intent'] ?? 'login';
        $phone = $this->formatPhone($data['indicatif'], $data['numero']);

        $userExists = TondoUser::where('project_id', Project::tondoId())
            ->where('numero', $phone)
            ->exists();

        // Early return : intent=login + user inexistant = pas la peine
        // d'envoyer un SMS, l'app va proposer la modale "créer compte".
        if ($intent === 'login' && ! $userExists) {
            Log::info("[mobile] request-otp skip SMS pour {$phone} — login sur numéro inexistant");
            return response()->json([
                'ok' => true,
                'message' => 'Numéro non inscrit.',
                'phone' => $phone,
                'user_exists' => false,
                'dev_hint' => null,
                'otp_sent' => false,
            ]);
        }

        $driver = config('services.otp.driver', 'dev');

        if ($driver === 'twilio') {
            $twilioPhone = $this->twilioRecipient($phone);
            try {
                app(TwilioVerifyService::class)->sendOtp($twilioPhone);
            } catch (Throwable $e) {
                Log::error("[mobile] request-otp twilio failed for {$twilioPhone} (saisi: {$phone}): {$e->getMessage()}");
                throw ValidationException::withMessages([
                    'numero' => 'Impossible d\'envoyer le code SMS. Vérifiez le numéro et réessayez.',
                ]);
            }
            $logSuffix = $twilioPhone !== $phone ? " → envoyé à {$twilioPhone} (override trial)" : '';
            Log::info("[mobile] request-otp twilio OK pour {$phone} — exists=" . ($userExists ? '1' : '0') . $logSuffix);

            return response()->json([
                'ok' => true,
                'message' => 'Code envoyé par SMS.',
                'phone' => $phone,
                'user_exists' => $userExists,
                'dev_hint' => null,
                'otp_sent' => true,
            ]);
        }

        // driver = dev : on n'envoie pas de SMS, on accepte 123456.
        Log::info("[mobile] request-otp dev pour {$phone} — exists=" . ($userExists ? '1' : '0'));

        return response()->json([
            'ok' => true,
            'message' => 'OTP envoyé.',
            'phone' => $phone,
            'user_exists' => $userExists,
            // En dev on retourne explicitement l'OTP pour faciliter les
            // tests Postman / Flutter. Toujours null en driver=twilio.
            'dev_hint' => self::OTP_DEV,
            'otp_sent' => true,
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

        $phone = $this->formatPhone($data['indicatif'], $data['numero']);

        // Vérification OTP selon le driver. En `twilio`, Twilio gère
        // l'expiration (10 min) et le rate-limit (5 essais max) côté
        // serveur — on récupère juste un bool. Si l'override est actif,
        // on check sur le numéro Twilio (= numéro de Daniel) au lieu
        // du numéro saisi.
        $driver = config('services.otp.driver', 'dev');
        if ($driver === 'twilio') {
            $twilioPhone = $this->twilioRecipient($phone);
            $ok = app(TwilioVerifyService::class)->checkOtp($twilioPhone, $data['otp']);
            if (! $ok) {
                throw ValidationException::withMessages([
                    'otp' => 'Code OTP invalide ou expiré.',
                ]);
            }
        } else {
            if ($data['otp'] !== self::OTP_DEV) {
                throw ValidationException::withMessages([
                    'otp' => 'Code OTP invalide.',
                ]);
            }
        }

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

            // Détection automatique de l'opérateur depuis le préfixe du numéro.
            $detected = app(OperateurDetectorService::class)->detect($projectId, $phone);
            if ($detected) {
                $user->operateur = $detected['operateur'];
                $user->pays      = $detected['pays'];
                $user->indicatif = $detected['indicatif'];
            }

            $user->save();

            $created = true;
        } else {
            $created = false;

            // Complétion silencieuse au login si l'opérateur n'a pas été détecté
            // lors du sign-up (utilisateurs anciens ou config ajoutée après).
            if (! $user->operateur) {
                $detected = app(OperateurDetectorService::class)->detect($projectId, $phone);
                if ($detected) {
                    $user->operateur = $detected['operateur'];
                    $user->pays      = $detected['pays'];
                    $user->indicatif = $detected['indicatif'];
                    $user->save();
                }
            }
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
     * Retourne le numéro réellement utilisé pour les appels Twilio.
     *
     * En compte payant, c'est toujours le numéro saisi par l'user.
     * En compte trial avec `TWILIO_OVERRIDE_RECIPIENT` défini dans
     * `.env`, on redirige tous les SMS vers ce numéro (qui doit être
     * un Verified Caller ID). Le numéro saisi reste enregistré tel
     * quel dans la table `users` pour les futurs login.
     */
    private function twilioRecipient(string $phoneSaisi): string
    {
        $override = config('services.twilio.override_recipient');
        return $override !== null && $override !== '' ? $override : $phoneSaisi;
    }

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
            'id'             => $user->id,
            'nom'            => $user->nom,
            'prenom'         => $user->prenom,
            'numero'         => $user->numero,
            'date_naissance' => $user->date_naissance?->toDateString(),
            'type_client'    => $user->type_client,
            'kyc_valide'     => $user->kyc_valide,
            'operateur'      => $user->operateur,
            'pays'           => $user->pays,
            'indicatif'      => $user->indicatif,
            'sexe'           => $user->sexe,
            'adresse'        => $user->adresse,
            'email'          => $user->email,
        ];
    }
}
