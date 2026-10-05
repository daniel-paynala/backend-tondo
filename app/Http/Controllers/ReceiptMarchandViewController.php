<?php

namespace App\Http\Controllers;

use App\Services\ReceiptMarchandService;
use Illuminate\View\View;

/**
 * Page publique de vérification d'un paiement marchand.
 *
 * Publique, comme le reçu de cotisation et pour la même raison : le lien est
 * partagé, et celui qui vérifie n'est pas forcément connecté — un commerçant
 * qui contrôle ce que son client lui montre n'a aucun compte Tonji.
 *
 * Ce qui tient lieu de protection est la référence elle-même : tirée au sort
 * sur 32 symboles, elle n'est pas énumérable. C'est pour cela que
 * {@see \App\Support\Registre::nouvelleReference()} utilise `random_int` et
 * non un aléa de confort.
 *
 * Routes :
 *   GET /recu-marchand/{transId}      → show()
 *   GET /recu-marchand/{transId}/pdf  → pdf()
 */
class ReceiptMarchandViewController extends Controller
{
    public function __construct(private readonly ReceiptMarchandService $recus) {}

    /** Page web de vérification. */
    public function show(string $transId): View
    {
        $donnees = $this->recus->donnees($transId);

        if ($donnees === null) {
            // Un seul message pour « inconnu », « non confirmé » et « ce n'est
            // pas un paiement marchand » : distinguer ces cas dirait à qui
            // tâtonne des références ce qui existe en base.
            abort(404, 'Paiement introuvable ou non confirmé.');
        }

        return view('receipts.marchand.page', $donnees);
    }

    /** Téléchargement du reçu en PDF. */
    public function pdf(string $transId): \Illuminate\Http\Response
    {
        $bytes = $this->recus->pdf($transId);

        if ($bytes === null) {
            abort(404, 'Paiement introuvable ou non confirmé.');
        }

        $reference = \App\Support\Registre::court($transId) ?? $transId;

        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="recu-tonji-' . $reference . '.pdf"',
            'Content-Length'      => strlen($bytes),
        ]);
    }
}
