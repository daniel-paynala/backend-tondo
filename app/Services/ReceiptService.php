<?php

namespace App\Services;

use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Str;

/**
 * Génère les reçus PDF de paiement et les stocke dans public/receipts/.
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

        $transId = $transaction['trans_id'] ?? Str::random(8);
        $qrUrl   = url('/recu/' . $transId);

        $data = [
            'trans_id'           => $transId,
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
            'qr_url'             => $qrUrl,
            'qr_data_uri'        => $this->genererQrDataUri($qrUrl),
        ];

        $pdf = Pdf::loadView('receipts.paiement', $data)
            ->setPaper('A6', 'portrait')
            ->setOptions([
                'defaultFont'     => 'DejaVu Sans',
                'isRemoteEnabled' => false,
                'dpi'             => 150,
            ]);

        $filename = 'recu-tonji-' . $transId . '.pdf';
        $dir      = public_path('receipts');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->nettoyerAnciensRecus($dir);

        file_put_contents($dir . '/' . $filename, $pdf->output());

        return url('receipts/' . $filename);
    }

    /**
     * Reconstruit les données d'un reçu à partir du trans_id (pour page web + regen PDF).
     */
    public function donneesDepuisTransId(string $transId): ?array
    {
        $payin = \Illuminate\Support\Facades\DB::table('tondo_payin as p')
            ->join('tondo_cagnottes as c', 'p.cagnotte_id', '=', 'c.id')
            ->leftJoin('users as u', 'p.user_id', '=', 'u.id')
            ->where('p.trans_id', $transId)
            ->where('p.statut', 'succes')
            ->select([
                'p.trans_id', 'p.montant', 'p.request', 'p.updated_at', 'p.numero_tel',
                'c.titre as cagnotte_titre', 'c.reference as cagnotte_reference', 'c.type as cagnotte_type',
                'u.nom', 'u.prenom', 'u.numero as user_numero',
            ])
            ->first();

        if (! $payin) {
            return null;
        }

        $req        = is_string($payin->request) ? (json_decode($payin->request, true) ?? []) : ($payin->request ?? []);
        $montantBrut = (int) $payin->montant;
        $montantNet  = (int) ($req['montant_net'] ?? $montantBrut);
        $canal       = ucfirst($req['canal'] ?? 'App mobile');
        $numero      = $payin->user_numero ?? $payin->numero_tel ?? '—';

        $nomCotisant = $payin->nom
            ? mb_strtoupper($payin->nom) . ' ' . ucfirst(mb_strtolower($payin->prenom))
            : 'Client';

        $qrUrl = url('/recu/' . $transId);

        return [
            'trans_id'           => $payin->trans_id,
            'montant_net'        => $montantNet,
            'montant_brut'       => $montantBrut,
            'frais'              => $montantBrut - $montantNet,
            'date_heure'         => \Carbon\Carbon::parse($payin->updated_at)->format('d/m/Y à H:i'),
            'canal'              => $canal,
            'cagnotte_titre'     => $payin->cagnotte_titre,
            'cagnotte_reference' => $payin->cagnotte_reference,
            'type_cagnotte'      => $payin->cagnotte_type === 'tontine_periodique' ? 'Tontine' : 'Cotisation',
            'nom_cotisant'       => $nomCotisant,
            'numero_masque'      => $this->maskPhone($numero),
            'qr_url'             => $qrUrl,
            'qr_data_uri'        => $this->genererQrDataUri($qrUrl),
        ];
    }

    /**
     * Régénère le PDF depuis le trans_id et retourne l'URL publique.
     */
    public function regenPdf(string $transId): ?string
    {
        $donnees = $this->donneesDepuisTransId($transId);
        if (! $donnees) {
            return null;
        }

        $pdf = Pdf::loadView('receipts.paiement', $donnees)
            ->setPaper('A6', 'portrait')
            ->setOptions([
                'defaultFont'     => 'DejaVu Sans',
                'isRemoteEnabled' => false,
                'dpi'             => 150,
            ]);

        $filename = 'recu-tonji-' . $transId . '.pdf';
        $dir      = public_path('receipts');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir . '/' . $filename, $pdf->output());

        return url('receipts/' . $filename);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function genererQrDataUri(string $url): string
    {
        $opts = new QROptions();
        $opts->outputType  = 'svg';
        $opts->scale       = 5;
        $opts->imageBase64 = true;

        return (new QRCode($opts))->render($url);
    }

    private function nettoyerAnciensRecus(string $dir): void
    {
        $limite = time() - 86400; // 24h
        foreach (glob($dir . '/recu-tonji-*.pdf') ?: [] as $fichier) {
            if (filemtime($fichier) < $limite) {
                @unlink($fichier);
            }
        }
    }

    public function maskPhone(string $phone): string
    {
        $clean = preg_replace('/[^\d+]/', '', $phone);
        if (strlen($clean) < 6) return $clean;
        $prefix = substr($clean, 0, strlen($clean) - 6);
        return $prefix . str_repeat('*', strlen($clean) - strlen($prefix) - 2) . substr($clean, -2);
    }
}
