<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TondoUser;
use App\Services\OperateurDetectorService;
use App\Services\PaynalaPaymentService;
use App\Services\OtpService;
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

        // Seuls les comptes full comptent comme "existants" pour le login.
        // Les comptes light (créés par ajout à une cagnotte) ne peuvent pas
        // se connecter — ils sont traités comme inexistants jusqu'à inscription.
        $userExists = TondoUser::where('project_id', Project::tondoId())
            ->where('numero', $phone)
            ->where('compte_type', 'full')
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

        // Early return : intent=signup + user déjà inscrit → l'app affiche
        // le toast "déjà inscrit" sans gaspiller un SMS ni appeler le KYC.
        if ($intent === 'signup' && $userExists) {
            Log::info("[mobile] request-otp skip SMS pour {$phone} — signup sur numéro déjà inscrit");
            return response()->json([
                'ok' => true,
                'message' => 'Numéro déjà inscrit.',
                'phone' => $phone,
                'user_exists' => true,
                'dev_hint' => null,
                'otp_sent' => false,
            ]);
        }

        // Vérification opérateur au moment du signup :
        //  - Airtel  : KYC en cache (déjà appelé par kycCheck depuis l'app) → on ne re-appelle pas ici.
        //  - Moov    : si la config Moov n'est pas active pour ce projet → inscription bloquée.
        //  - Inconnu : on laisse passer (KYC indisponible ou opérateur hors périmètre).
        //
        // EXCEPTION — numéro de test (revue Apple / Google) : on saute entièrement
        // ce bloc. Le numéro est fictif, aucun compte Airtel Money réel n'existe
        // derrière, donc le KYC le rejetterait et le relecteur ne pourrait jamais
        // s'inscrire. Ne concerne que MOBILE_TEST_MSISDN, et rien d'autre.
        if ($intent === 'signup' && ! app(OtpService::class)->estNumeroTest($phone)) {
            $projectId = Project::tondoId();
            $detected  = app(OperateurDetectorService::class)->detect($projectId, $phone);

            if ($detected) {
                $operateur = $detected['operateur'];
                $pays      = $detected['pays'];

                // Vérifie si cet opérateur est marqué actif dans la config projet.
                // Si la ligne n'existe pas du tout → considéré comme inactif.
                $configActif = \App\Models\TondoProjectConfig::where('project_id', $projectId)
                    ->where('operateur', $operateur)
                    ->where('pays', $pays)
                    ->value('actif');

                if ($configActif === false || $configActif === 0) {
                    // Opérateur désactivé dans la config (ex : Moov sans API KYC disponible)
                    throw ValidationException::withMessages([
                        'numero' => "L'opérateur {$operateur} n'est pas encore disponible sur Tonji. "
                            . 'Utilisez un numéro Airtel Money pour vous inscrire.',
                    ]);
                }

                // Airtel actif : le KYC a déjà été mis en cache par kycCheck() appelé
                // depuis l'app pendant la saisie. Si absent du cache (saisie directe sans
                // appel kycCheck), on vérifie maintenant — null = indisponible, on laisse passer.
                if ($operateur === 'airtel') {
                    $msisdn = '0' . ltrim($data['numero'], '0');
                    $kycOk  = app(PaynalaPaymentService::class)->checkKyc($msisdn);
                    if ($kycOk === false) {
                        throw ValidationException::withMessages([
                            'numero' => 'Ce numéro ne possède pas de compte Airtel Money actif.',
                        ]);
                    }
                }
            }
        }

        $devHint = null;

        try {
            // OtpService résolu ici pour que toute exception de configuration
            // (ex : WirepickSmsService mal configuré) soit capturée et transformée
            // en message utilisateur lisible, sans exposer les détails internes.
            $otp     = app(OtpService::class);
            $devHint = $otp->sendOtp($phone);
        } catch (Throwable $e) {
            Log::error("[mobile] request-otp failed for {$phone}: {$e->getMessage()}");
            throw ValidationException::withMessages([
                'numero' => 'Le code n\'a pas pu être envoyé. Vérifiez votre numéro et réessayez.',
            ]);
        }

        Log::info("[mobile] request-otp [{$otp->driver()}] OK pour {$phone} — exists=" . ($userExists ? '1' : '0'));

        return response()->json([
            'ok'          => true,
            'message'     => 'Code envoyé par SMS.',
            'phone'       => $phone,
            'user_exists' => $userExists,
            'dev_hint'    => $devHint,  // null en prod, code en driver=dev
            'otp_sent'    => true,
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

        // Vérification déléguée à OtpService — gère dev/twilio/paynala.
        if (! app(OtpService::class)->checkOtp($phone, $data['otp'])) {
            throw ValidationException::withMessages([
                'otp' => 'Code OTP invalide ou expiré.',
            ]);
        }

        $projectId = Project::tondoId();

        $user = TondoUser::where('project_id', $projectId)
            ->where('numero', $phone)
            ->first();

        $isLightUpgrade = $user && ($user->compte_type ?? 'full') === 'light';

        if (! $user || $isLightUpgrade) {
            // Sign-up ou upgrade d'un compte light.
            // Pour un compte light, nom/prénom sont déjà connus — seule
            // la date de naissance est obligatoire en plus (RÈGLE 4-bis).
            $missing = [];
            if (! $isLightUpgrade) {
                foreach (['nom', 'prenom'] as $f) {
                    if (empty($data[$f])) {
                        $missing[$f] = "Le champ {$f} est requis pour la création du compte.";
                    }
                }
            }
            if (empty($data['date_naissance'])) {
                $missing['date_naissance'] = 'La date de naissance est requise.';
            }
            if (! empty($missing)) {
                throw ValidationException::withMessages($missing);
            }

            if (! $user) {
                $user = new TondoUser();
                $user->id         = (string) Str::uuid();
                $user->project_id = $projectId;
                $user->numero     = $phone;
            }

            // On met à jour (ou initialise) les champs — les champs du compte
            // light existant sont conservés si pas fournis dans la requête.
            if (! empty($data['nom']))    $user->nom    = $data['nom'];
            if (! empty($data['prenom'])) $user->prenom = $data['prenom'];
            $user->date_naissance = $data['date_naissance'];
            // Priorité : grade KYC Airtel (mis en cache lors du request-otp)
            // > valeur explicitement passée par le client > valeur existante > défaut.
            $kycMsisdn     = '0' . ltrim($data['numero'], '0');
            $kycTypeClient = app(PaynalaPaymentService::class)->resolveTypeClientFromKyc($kycMsisdn);
            $user->type_client = $kycTypeClient ?? $data['type_client'] ?? $user->type_client ?? 'particulier';
            $user->compte_type    = 'full';
            // KYC déjà vérifié dans requestOtp (intent=signup) — on marque
            // kyc_valide=true pour les numéros Airtel qui ont passé ce contrôle.
            $detected = app(OperateurDetectorService::class)->detect($projectId, $phone);
            if ($detected) {
                $user->operateur  = $detected['operateur'];
                $user->pays       = $detected['pays'];
                $user->indicatif  = $detected['indicatif'];
                $user->kyc_valide = $detected['operateur'] === 'airtel';
            } else {
                $user->kyc_valide = false;
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
     * GET /api/mobile/auth/kyc-check?numero=0XXXXXXXX
     *
     * Point d'entrée unique appelé par l'app quand l'utilisateur appuie sur
     * « Continuer » à l'étape 0 de l'inscription.
     *
     * Ordre de vérification :
     *  1. Compte Tonji existant → user_exists: true, bloque: true (aller se connecter).
     *  2. Opérateur inconnu       → bloque: true (seul Airtel accepté pour l'instant).
     *  3. Opérateur inactif (Moov, etc.) → bloque: true.
     *  4. Service KYC Airtel indisponible → bloque: true (on ne laisse pas passer sans vérif).
     *  5. Numéro sans compte Airtel Money → bloque: true.
     *  6. KYC réussi → kyc_ok: true, nom + prénom pour auto-complétion.
     *
     * L'app ne doit JAMAIS laisser l'utilisateur passer à l'étape 1 si bloque: true.
     *
     * Réponse :
     *   { user_exists, operateur, kyc_ok, bloque, nom?, prenom?, type_client?, message }
     */
    public function kycCheck(Request $request): JsonResponse
    {
        $data = $request->validate([
            'numero' => ['required', 'string', 'regex:/^0\d{8}$/'],
        ]);

        $projectId = Project::tondoId();
        $msisdn    = $data['numero'];           // 0XXXXXXXX
        $phoneE164 = '+241' . substr($msisdn, 1);

        // ── 1. Vérification d'existence du compte Tonji ─────────────────────
        // Seuls les comptes "full" bloquent — les comptes light (ajoutés à une
        // cagnotte par un tiers) peuvent se créer un compte complet.
        $userExists = TondoUser::where('project_id', $projectId)
            ->where('numero', $phoneE164)
            ->where('compte_type', 'full')
            ->exists();

        if ($userExists) {
            return response()->json([
                'user_exists' => true,
                'operateur'   => null,
                'kyc_ok'      => null,
                'bloque'      => true,
                'message'     => 'Ce numéro est déjà inscrit sur Tonji. Retournez à l\'accueil pour vous connecter.',
            ]);
        }

        // ── 1-bis. Numéro de test (revue Apple / Google) ─────────────────────
        // Court-circuite toute la vérification opérateur + KYC qui suit : le
        // numéro est fictif, aucun compte Airtel Money réel n'existe derrière,
        // donc le KYC le rejetterait et le relecteur resterait bloqué à la
        // saisie du numéro. On renvoie une identité de test pour pré-remplir
        // le formulaire d'inscription.
        // Placé APRÈS le contrôle d'existence : une fois le compte de test créé,
        // le relecteur doit bien recevoir « déjà inscrit » et passer par le login.
        if (app(OtpService::class)->estNumeroTest($phoneE164)) {
            return response()->json([
                'user_exists' => false,
                'operateur'   => 'airtel',
                'kyc_ok'      => true,
                'bloque'      => false,
                'nom'         => config('services.otp.test_nom', 'REVIEW'),
                'prenom'      => config('services.otp.test_prenom', 'Test'),
                'type_client' => 'particulier',
                'message'     => 'Compte Airtel Money vérifié.',
            ]);
        }

        // ── 2. Détection de l'opérateur ─────────────────────────────────────
        $detected = app(OperateurDetectorService::class)->detect($projectId, $phoneE164);

        if (! $detected) {
            // Préfixe non configuré → seul Airtel est accepté pour l'instant
            return response()->json([
                'user_exists' => false,
                'operateur'   => null,
                'kyc_ok'      => null,
                'bloque'      => true,
                'message'     => 'Le numéro doit être Airtel Money.',
            ]);
        }

        $operateur = $detected['operateur'];
        $pays      = $detected['pays'];

        // ── 3. Vérification que l'opérateur est activé dans la config projet ─
        $configActif = \App\Models\TondoProjectConfig::where('project_id', $projectId)
            ->where('operateur', $operateur)
            ->where('pays', $pays)
            ->value('actif');

        if (! $configActif) {
            return response()->json([
                'user_exists' => false,
                'operateur'   => $operateur,
                'kyc_ok'      => null,
                'bloque'      => true,
                'message'     => 'Le numéro doit être Airtel Money.',
            ]);
        }

        // ── 4. Seul Airtel a une API KYC — tout autre opérateur actif est bloqué ─
        if ($operateur !== 'airtel') {
            return response()->json([
                'user_exists' => false,
                'operateur'   => $operateur,
                'kyc_ok'      => null,
                'bloque'      => true,
                'message'     => 'Le numéro doit être Airtel Money.',
            ]);
        }

        // ── 5 & 6. Appel KYC Airtel ─────────────────────────────────────────
        $kycData = app(PaynalaPaymentService::class)->checkKycData($msisdn);

        // Service indisponible → on bloque (pas d'inscription sans vérification)
        if ($kycData === null) {
            return response()->json([
                'user_exists' => false,
                'operateur'   => 'airtel',
                'kyc_ok'      => null,
                'bloque'      => true,
                'message'     => 'La vérification Airtel Money est temporairement indisponible. '
                    . 'Réessayez dans quelques minutes.',
            ]);
        }

        // Numéro sans compte Airtel Money actif → bloqué
        if (! $kycData['ok']) {
            return response()->json([
                'user_exists' => false,
                'operateur'   => 'airtel',
                'kyc_ok'      => false,
                'bloque'      => true,
                'message'     => 'Ce numéro n\'a pas de compte Airtel Money actif. '
                    . 'Vérifiez votre numéro ou contactez Airtel.',
            ]);
        }

        // KYC réussi → nom et prénom pour auto-complétion du formulaire
        return response()->json([
            'user_exists' => false,
            'operateur'   => 'airtel',
            'kyc_ok'      => true,
            'bloque'      => false,
            'nom'         => $kycData['nom']        ?? '',
            'prenom'      => $kycData['prenom']      ?? '',
            'type_client' => $kycData['type_client'] ?? 'particulier',
            'message'     => 'Compte Airtel Money vérifié.',
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
