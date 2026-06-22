<?php

use App\Http\Controllers\ReceiptViewController;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Vérification de reçu — ENTIÈREMENT PUBLIC, zéro authentification.
 *
 * Ces routes sont ouvertes à tous (lien WhatsApp partagé, QR code scanné
 * par n'importe qui). withoutMiddleware() exclut explicitement tout
 * middleware d'auth qui pourrait être ajouté au groupe web plus tard.
 *
 *  GET /recu/{transId}       → page web de vérification avec QR + bouton téléchargement
 *  GET /recu/{transId}/pdf   → régénère le PDF et redirige vers l'URL publique
 */
Route::prefix('recu')
    ->withoutMiddleware([
        StartSession::class,          // pas de session → pas de table "sessions" requise
        'auth', 'auth:sanctum', 'auth:api', 'auth.basic',
    ])
    ->group(function () {
        Route::get('/{transId}',      [ReceiptViewController::class, 'show']);
        Route::get('/{transId}/pdf',  [ReceiptViewController::class, 'pdf']);
    });
