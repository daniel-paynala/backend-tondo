<?php

namespace App\Http\Controllers;

use App\Services\ReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Contrôleur public pour la visualisation et le téléchargement des reçus de paiement.
 *
 * Ces routes sont publiques (aucune auth requise) car :
 *  - Le lien est partagé par WhatsApp — le destinataire n'est pas forcément connecté.
 *  - Le trans_id est un identifiant opaque suffisamment long pour éviter l'énumération.
 *
 * Routes déclarées :
 *   GET /recu/{transId}      → show()  — page HTML de vérification.
 *   GET /recu/{transId}/pdf  → pdf()   — téléchargement du PDF.
 */
class ReceiptViewController extends Controller
{
    /**
     * Injection du service de reçus par le conteneur Laravel.
     *
     * @param ReceiptService $receiptSvc  Service de génération et de reconstruction des reçus.
     */
    public function __construct(private readonly ReceiptService $receiptSvc) {}

    /**
     * GET /recu/{transId}
     * Page web de vérification du reçu — publique, aucune auth requise.
     */
    public function show(string $transId): View|\Illuminate\Http\Response
    {
        // Reconstruit les données du reçu depuis la table tondo_payin (trans_id Airtel).
        $donnees = $this->receiptSvc->donneesDepuisTransId($transId);

        if (! $donnees) {
            // 404 si le trans_id est inconnu ou si le statut n'est pas 'succes'.
            abort(404, 'Reçu introuvable ou transaction non confirmée.');
        }

        // Passe toutes les données directement à la vue (pas de compact() volontairement).
        return view('receipts.show', $donnees);
    }

    /**
     * GET /recu/{transId}/pdf
     * Régénère le PDF à la demande et redirige vers l'URL de téléchargement.
     *
     * La redirection (302) vers l'URL publique du fichier permet au navigateur
     * et à WhatsApp de télécharger le PDF directement sans passer par Laravel.
     */
    public function pdf(string $transId): RedirectResponse|\Illuminate\Http\Response
    {
        // Régénère (ou utilise le cache disque s'il existe encore) le fichier PDF.
        $url = $this->receiptSvc->regenPdf($transId);

        if (! $url) {
            abort(404, 'Transaction introuvable ou non confirmée.');
        }

        // Redirection vers public/receipts/recu-tonji-{transId}.pdf.
        return redirect($url);
    }
}
