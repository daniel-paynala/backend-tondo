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
//  API Dashboard — préfixe /api/admin/
//  Auth : Sanctum (guard 'admin'), tokens sur tondo_admins.
// ============================================================================
Route::prefix('admin')->group(function () {
    // Public
    Route::post('/login', [AuthController::class, 'login']);

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

        // Tontines & cotisations (tondo_cagnottes)
        Route::get('/tontines', [TontinesController::class, 'index']);
        Route::get('/tontines/{id}', [TontinesController::class, 'show']);
        Route::post('/tontines/{id}/cloturer', [TontinesController::class, 'cloturer']);

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
    // Public — flow OTP
    Route::post('/auth/request-otp', [MobileAuthController::class, 'requestOtp']);
    Route::post('/auth/verify-otp',  [MobileAuthController::class, 'verifyOtp']);

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
        Route::post('/cagnottes/{reference}/rejoindre',            [MobileCagnottesController::class, 'rejoindre']);
        Route::post('/cagnottes/{reference}/participants',          [MobileCagnottesController::class, 'storeParticipant']);
        Route::post('/cagnottes/{reference}/participants/ordre',    [MobileCagnottesController::class, 'ordonnerParticipants']);
        Route::delete('/cagnottes/{reference}/participants/moi',   [MobileCagnottesController::class, 'quitter']);

        // Cotisations (payin)
        Route::post('/cotisations', [MobileCotisationsController::class, 'store']);
        Route::get('/cotisations/{trans_id}/status', [MobileCotisationsController::class, 'status']);

        // Reversements partiels (payout gérant → bénéficiaire, cagnotte ouverte)
        Route::post('/reversements', [MobileReversementsController::class, 'store']);
    });
});
