<?php

namespace App\Services;

use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

/**
 * Génère les reçus PDF de paiement et les stocke dans storage/app/public/receipts/.
 * Retourne l'URL publique du fichier pour l'envoyer par WhatsApp ou e-mail.
 */
class ReceiptService
{
    /**
     * Génère le PDF et retourne son URL publique.
     *
     * @param TondoUser|null     $user
     * @param TondoCagnotte|null $cagnotte
     * @param array              $transaction  [trans_id, montant_net, montant_brut, frais]
     * @param string             $canal        'WhatsApp' | 'App mobile' | 'Web'
     * @return string  URL publique du PDF
     */
    public function generer(
        ?TondoUser    $user,
        ?TondoCagnotte $cagnotte,
        array          $transaction,
        string         $canal = 'App mobile',
    ): string {
        $nomCotisant = $user
            ? mb_strtoupper($user->nom) . ' ' . ucfirst(mb_strtolower($user->prenom))
            : 'Client';

        $numeroMasque = $user ? $this->maskPhone($user->numero ?? '') : '—';

        $data = [
            'trans_id'           => $transaction['trans_id']    ?? '—',
            'montant_net'        => (int) ($transaction['montant_net']  ?? 0),
            'montant_brut'       => (int) ($transaction['montant_brut'] ?? $transaction['montant_net'] ?? 0),
            'frais'              => (int) ($transaction['frais']        ?? 0),
            'date_heure'         => now()->format('d/m/Y à H:i'),
            'canal'              => $canal,
            'cagnotte_titre'     => $cagnotte?->titre     ?? '—',
            'cagnotte_reference' => $cagnotte?->reference ?? '—',
            'type_cagnotte'      => $cagnotte
                ? ($cagnotte->type === 'tontine_periodique' ? 'Tontine' : 'Cotisation')
                : '—',
            'nom_cotisant'       => $nomCotisant,
            'numero_masque'      => $numeroMasque,
        ];

        $pdf = Pdf::loadView('receipts.paiement', $data)
            ->setPaper('A4', 'portrait')
            ->setOptions([
                'defaultFont'     => 'DejaVu Sans',
                'isRemoteEnabled' => false,
                'dpi'             => 150,
            ]);

        $filename = 'recu-tondo-' . ($transaction['trans_id'] ?? Str::random(8)) . '.pdf';
        $dir      = public_path('receipts');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->nettoyerAnciensRecus($dir);

        file_put_contents($dir . '/' . $filename, $pdf->output());

        return url('receipts/' . $filename);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function nettoyerAnciensRecus(string $dir): void
    {
        $limite = time() - 86400; // 24h
        foreach (glob($dir . '/recu-tondo-*.pdf') ?: [] as $fichier) {
            if (filemtime($fichier) < $limite) {
                @unlink($fichier);
            }
        }
    }

    private function maskPhone(string $phone): string
    {
        $clean = preg_replace('/[^\d+]/', '', $phone);
        if (strlen($clean) < 6) return $clean;
        $prefix = substr($clean, 0, strlen($clean) - 6);
        return $prefix . str_repeat('*', strlen($clean) - strlen($prefix) - 2) . substr($clean, -2);
    }
}
