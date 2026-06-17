<?php

namespace App\Http\Controllers;

use App\Services\ReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ReceiptViewController extends Controller
{
    public function __construct(private readonly ReceiptService $receiptSvc) {}

    /**
     * GET /recu/{transId}
     * Page web de vérification du reçu — publique, aucune auth requise.
     */
    public function show(string $transId): View|\Illuminate\Http\Response
    {
        $donnees = $this->receiptSvc->donneesDepuisTransId($transId);

        if (! $donnees) {
            abort(404, 'Reçu introuvable ou transaction non confirmée.');
        }

        return view('receipts.show', $donnees);
    }

    /**
     * GET /recu/{transId}/pdf
     * Régénère le PDF à la demande et redirige vers l'URL de téléchargement.
     */
    public function pdf(string $transId): RedirectResponse|\Illuminate\Http\Response
    {
        $url = $this->receiptSvc->regenPdf($transId);

        if (! $url) {
            abort(404, 'Transaction introuvable ou non confirmée.');
        }

        return redirect($url);
    }
}
