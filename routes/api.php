<?php

use App\Http\Controllers\Api\Admin\AdminsController;
use App\Http\Controllers\Api\Admin\AuthController;
use App\Http\Controllers\Api\Admin\ConfigController as AdminConfigController;
use App\Http\Controllers\Api\Admin\LogsController;
use App\Http\Controllers\Api\Admin\ReconciliationController;
use App\Http\Controllers\Api\Admin\SignalementsController;
use App\Http\Controllers\Api\Admin\TontinesController;
use App\Http\Controllers\Api\Admin\TransactionsController;
use App\Http\Controllers\Api\Admin\UsersController;
use App\Http\Controllers\Api\Mobile\AuthController as MobileAuthController;
use App\Http\Controllers\Api\Mobile\CagnottesController as MobileCagnottesController;
use App\Http\Controllers\Api\Mobile\ConfigController as MobileConfigController;
use App\Http\Controllers\Api\Mobile\CotisationsController as MobileCotisationsController;
use App\Http\Controllers\Api\Mobile\ReversementsController as MobileReversementsController;
use App\Http\Controllers\Api\Mobile\ProfilController as MobileProfilController;
use App\Http\Controllers\Api\Admin\CagnottesController as AdminCagnottesController;
use App\Http\Controllers\Api\Public\CagnottesController as PublicCagnottesController;
use App\Http\Controllers\Api\WhatsApp\WebhookController as WhatsAppWebhookController;
use App\Http\Controllers\Api\WhatsApp\StatusController as WhatsAppStatusController;
use App\Http\Controllers\Api\Ussd\UssdController;
use Illuminate\Support\Facades\Route;

// ============================================================================
//  Health check public
// ============================================================================
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'tondo-backend',
    'time' => now()->toIso8601String(),
]));

// ============================================================================
//  Canal WhatsApp — préfixe /api/whatsapp/
//  Public (pas d'auth Sanctum) — sécurisé par validation de la signature
//  X-Twilio-Signature à l'intérieur du controller.
//  URL à saisir dans la console Twilio > Messaging > WhatsApp Senders > Webhook URL
// ============================================================================
Route::prefix('whatsapp')->group(function () {
    Route::post('/webhook', [WhatsAppWebhookController::class, 'recevoir']);
    Route::post('/status',  [WhatsAppStatusController::class,  'recevoir']);
});

// ============================================================================
//  Canal USSD — préfixe /api/ussd/
//  Public (pas d'auth Sanctum) — sécurisé par l'entête X-Ussd-Secret
//  dont la valeur doit correspondre à la variable d'environnement USSD_SECRET.
//  Appelé par la passerelle USSD de l'opérateur (Airtel, Moov, etc.).
// ============================================================================
Route::prefix('ussd')->group(function () {
    // Infos d'une cagnotte + confirmation du MSISDN cotisant
    Route::get('/cagnotte/{reference}', [UssdController::class, 'infos']);

    // Initiation du paiement (validation montant + lancement Mobile Money)
    Route::post('/cotiser', [UssdController::class, 'cotiser']);
});

// ============================================================================
//  Cagnottes PUBLIQUES — préfixe /api/public/
//  Sans auth : liste + détail des cagnottes publiques APPROUVÉES (Explorer mobile
//  et page publique web). N'expose aucune donnée sensible (pas de n° de retrait).
// ============================================================================
Route::prefix('public')->group(function () {
    Route::get('/cagnottes',             [PublicCagnottesController::class, 'index']);
    Route::get('/cagnottes/{reference}', [PublicCagnottesController::class, 'show']);
});

// ============================================================================
//  API Dashboard — préfixe /api/admin/
//  Auth : Sanctum (guard 'admin'), tokens sur tondo_admins.
// ============================================================================
Route::prefix('admin')->group(function () {
    // Public — throttlé (anti brute-force mot de passe admin).
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:admin-login');

    // Protégé par token Sanctum
    Route::middleware('auth:admin')->group(function () {
        // Auth / session
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Utilisateurs Tondo (mobile end-users)
        Route::get('/users', [UsersController::class, 'index']);
        Route::get('/users/{id}', [UsersController::class, 'show']);

        // Administrateurs (CRUD, restrictions super_admin côté controller)
        Route::get('/admins', [AdminsController::class, 'index']);
        Route::post('/admins', [AdminsController::class, 'store']);
        Route::get('/admins/{id}', [AdminsController::class, 'show']);
        Route::patch('/admins/{id}', [AdminsController::class, 'update']);
        Route::delete('/admins/{id}', [AdminsController::class, 'destroy']);

        // Tontines & cagnottes (tondo_cagnottes)
        Route::get('/tontines', [TontinesController::class, 'index']);
        Route::get('/tontines/{id}', [TontinesController::class, 'show']);
        Route::post('/tontines/{id}/cloturer', [TontinesController::class, 'cloturer']);

        // Modération des cagnottes PUBLIQUES (validation crowdfunding)
        Route::get('/cagnottes/moderation',              [AdminCagnottesController::class, 'moderation']);
        Route::post('/cagnottes/{reference}/approuver',  [AdminCagnottesController::class, 'approuver']);
        Route::post('/cagnottes/{reference}/rejeter',    [AdminCagnottesController::class, 'rejeter']);
        Route::post('/cagnottes/{reference}/suspendre',  [AdminCagnottesController::class, 'suspendre']);

        // Transactions
        Route::get('/transactions', [TransactionsController::class, 'index']);
        Route::get('/transactions/payin', [TransactionsController::class, 'payin']);
        Route::get('/transactions/payout', [TransactionsController::class, 'payout']);
        Route::get('/transactions/payout-paynala', [TransactionsController::class, 'payoutPaynala']);

        // Signalements
        Route::get('/signalements', [SignalementsController::class, 'index']);
        Route::get('/signalements/{id}', [SignalementsController::class, 'show']);
        Route::patch('/signalements/{id}', [SignalementsController::class, 'update']);

        // Logs (audit)
        Route::get('/logs', [LogsController::class, 'index']);

        // Configuration tarifaire per-opérateur / per-pays (CRUD)
        Route::get('/config',                               [AdminConfigController::class, 'index']);
        Route::post('/config',                              [AdminConfigController::class, 'store']);
        Route::patch('/config/{operateur}/{pays}',          [AdminConfigController::class, 'update']);
        Route::post('/config/{operateur}/{pays}/toggle',    [AdminConfigController::class, 'toggle']);
        Route::delete('/config/{operateur}/{pays}',         [AdminConfigController::class, 'destroy']);

        // Réconciliation financière
        Route::get('/reconcile',                             [ReconciliationController::class, 'index']);
        Route::get('/cagnottes/{reference}/reconcile',       [ReconciliationController::class, 'show']);
    });
});

// ============================================================================
//  API Mobile — préfixe /api/mobile/
//  Auth : Sanctum guard `mobile` + OTP statique 123456 en dev. À swapper
//  contre un middleware `verify.supabase.jwt` quand on branchera Supabase
//  Auth phone OTP en prod (les controllers ne changent pas).
// ============================================================================
Route::prefix('mobile')->group(function () {
    // Public — flow OTP + vérification KYC pendant saisie. Throttlés par numéro
    // (cf. AppServiceProvider) : anti-spam SMS, anti brute-force, anti-énumération.
    Route::post('/auth/request-otp', [MobileAuthController::class, 'requestOtp'])->middleware('throttle:otp-request');
    Route::post('/auth/verify-otp',  [MobileAuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
    // Appelé dès que le champ téléphone atteint 9 chiffres — pas d'auth
    Route::get('/auth/kyc-check',    [MobileAuthController::class, 'kycCheck'])->middleware('throttle:kyc-check');

    // Protégé par token Sanctum (guard mobile)
    Route::middleware('auth:mobile')->group(function () {
        // Auth / session
        Route::get('/auth/me',     [MobileAuthController::class, 'me']);
        Route::post('/auth/logout',[MobileAuthController::class, 'logout']);

        // Profil
        Route::get('/profil',              [MobileProfilController::class, 'show']);
        Route::patch('/profil',            [MobileProfilController::class, 'update']);
        Route::post('/profil/kyc-recheck',        [MobileProfilController::class, 'recheckKyc']);
        Route::post('/kyc/verifier-numero',        [MobileProfilController::class, 'verifierNumeroRetrait']);

        // Config dynamique (taux de frais, pilotés serveur)
        Route::get('/config/frais', [MobileConfigController::class, 'frais']);

        // Lookup numéro (autocompletion ajout participant)
        Route::get('/users/lookup', [MobileProfilController::class, 'lookup']);

        // Cagnottes (gérant)
        Route::get('/cagnottes/generate-reference',           [MobileCagnottesController::class, 'generateReference']);
        Route::get('/cagnottes',                              [MobileCagnottesController::class, 'index']);
        Route::post('/cagnottes',                             [MobileCagnottesController::class, 'store']);
        Route::get('/cagnottes/{reference}',                  [MobileCagnottesController::class, 'show']);
        Route::delete('/cagnottes/{reference}',               [MobileCagnottesController::class, 'destroy']);
        Route::post('/cagnottes/{reference}/cloturer',              [MobileCagnottesController::class, 'cloturer']);
        Route::post('/cagnottes/{reference}/fermer',               [MobileCagnottesController::class, 'fermer']);
        Route::post('/cagnottes/{reference}/demarrer',              [MobileCagnottesController::class, 'demarrer']);
        Route::post('/cagnottes/{reference}/rappel',               [MobileCagnottesController::class, 'rappel']);
        Route::patch('/cagnottes/{reference}/reversement-auto',    [MobileCagnottesController::class, 'patchReversementAuto']);
        Route::post('/cagnottes/{reference}/rejoindre',            [MobileCagnottesController::class, 'rejoindre']);
        Route::post('/cagnottes/{reference}/participants',          [MobileCagnottesController::class, 'storeParticipant']);
        Route::post('/cagnottes/{reference}/participants/ordre',    [MobileCagnottesController::class, 'ordonnerParticipants']);
        Route::delete('/cagnottes/{reference}/participants/moi',            [MobileCagnottesController::class, 'quitter']);
        Route::delete('/cagnottes/{reference}/participants/{participantId}', [MobileCagnottesController::class, 'retirerParticipant']);

        // Cotisations (payin)
        Route::post('/cotisations', [MobileCotisationsController::class, 'store']);
        Route::get('/cotisations/{trans_id}/status', [MobileCotisationsController::class, 'status']);

        // Reversements partiels (payout gérant → bénéficiaire, cagnotte ouverte)
        Route::post('/reversements', [MobileReversementsController::class, 'store']);
    });
});
