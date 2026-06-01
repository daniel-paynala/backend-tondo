<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\PaynalaPaymentService;
use App\Services\TondoConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
     * POST /api/mobile/profil/kyc-recheck
     *
     * Re-vérifie si le numéro de l'utilisateur est bien un compte
     * Airtel Money actif. Efface le cache KYC pour forcer un appel frais.
     *
     * Réponses :
     *  200 + user     → KYC confirmé, kyc_valide mis à true.
     *  422            → numéro introuvable sur Airtel Money.
     *  503            → API Paynala indisponible, réessayer plus tard.
     */
    public function recheckKyc(Request $request): JsonResponse
    {
        $user    = $request->user();
        $numero  = $user->numero; // E164 : +24177XXXXXX

        // Numéro local 9 chiffres pour l'API Paynala (077XXXXXX).
        $numeroNet = preg_replace('/\s+/', '', $numero);
        $msisdn = str_starts_with($numeroNet, '+241')
            ? '0' . substr($numeroNet, 4)
            : (str_starts_with($numeroNet, '241')
                ? '0' . substr($numeroNet, 3)
                : ltrim($numeroNet, '+'));

        // Effacer le cache pour forcer une nouvelle vérification.
        Cache::forget('paynala_kyc_' . $msisdn);
        Cache::forget('paynala_kyc_type_' . $msisdn);

        $paynala = app(PaynalaPaymentService::class);
        $result  = $paynala->checkKyc($msisdn);

        if ($result === false) {
            return response()->json([
                'message' => 'Ce numéro ne possède pas de compte Airtel Money actif.',
            ], 422);
        }

        if ($result === null) {
            return response()->json([
                'message' => 'La vérification Mobile Money est en cours d\'activation. Réessayez dans quelques heures ou contactez le support.',
            ], 503);
        }

        // KYC confirmé — mettre à jour l'utilisateur.
        $user->kyc_valide = true;

        // Profiter du type_client résolu par le KYC si disponible.
        $typeFromKyc = $paynala->resolveTypeClientFromKyc($msisdn);
        if ($typeFromKyc) {
            $user->type_client = $typeFromKyc;
        }

        $user->save();

        return response()->json([
            'message' => 'Numéro Mobile Money vérifié avec succès.',
            'user'    => [
                'id'             => $user->id,
                'nom'            => $user->nom,
                'prenom'         => $user->prenom,
                'numero'         => $user->numero,
                'date_naissance' => $user->date_naissance?->toDateString(),
                'type_client'    => $user->type_client,
                'kyc_valide'     => $user->kyc_valide,
                'sexe'           => $user->sexe,
                'adresse'        => $user->adresse,
                'email'          => $user->email,
            ],
        ]);
    }

    /**
     * POST /api/mobile/kyc/verifier-numero
     * Body : { numero: '077XXXXXX' } — 9 chiffres format local.
     *
     * Détecte l'opérateur et vérifie le compte Mobile Money si Airtel.
     * Réponse : { operateur, kyc_ok, message }
     *   operateur : 'airtel' | 'moov' | 'inconnu'
     *   kyc_ok    : true (Airtel vérifié) | false (pas de compte) | null (indispo/Moov)
     */
    public function verifierNumeroRetrait(Request $request): JsonResponse
    {
        $data = $request->validate([
            'numero' => ['required', 'string', 'regex:/^0\d{8}$/'],
        ]);

        $user   = $request->user();
        $msisdn = $data['numero'];
        $e164   = '+241' . substr($msisdn, 1);

        $opInfo       = app(TondoConfigService::class)->detectOperateur($e164, $user->project_id);
        $operateurSlug = strtolower($opInfo['operateur'] ?? 'inconnu');

        if ($operateurSlug === 'airtel') {
            $kycOk = app(PaynalaPaymentService::class)->checkKyc($msisdn);
            return response()->json([
                'operateur' => 'airtel',
                'kyc_ok'    => $kycOk,
                'message'   => match($kycOk) {
                    true  => 'Compte Airtel Money vérifié.',
                    false => 'Ce numéro ne possède pas de compte Airtel Money actif.',
                    null  => 'Vérification indisponible pour l\'instant.',
                },
            ]);
        }

        if ($operateurSlug === 'moov') {
            return response()->json([
                'operateur' => 'moov',
                'kyc_ok'    => null,
                'message'   => 'Assurez-vous d\'avoir un compte Moov Money actif sur ce numéro.',
            ]);
        }

        return response()->json([
            'operateur' => $operateurSlug,
            'kyc_ok'    => null,
            'message'   => 'Opérateur non reconnu.',
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
