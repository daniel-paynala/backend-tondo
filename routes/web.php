<?php

use App\Http\Controllers\ReceiptViewController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Vérification de reçu — public, sans authentification.
 *
 *  GET /recu/{transId}       → page web avec QR + bouton téléchargement
 *  GET /recu/{transId}/pdf   → régénère le PDF et redirige vers le lien de téléchargement
 */
Route::prefix('recu')->group(function () {
    Route::get('/{transId}',      [ReceiptViewController::class, 'show']);
    Route::get('/{transId}/pdf',  [ReceiptViewController::class, 'pdf']);
});
