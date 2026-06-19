<?php

namespace App\Services;

use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Log;
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
        $transId = $transaction['trans_id'] ?? Str::random(8);

        // Point d'entrée loggé — confirme que generer() est bien appelé.
        Log::info('ReceiptService::generer — début', [
            'trans_id'  => $transId,
            'canal'     => $canal,
            'user_id'   => $user?->id,
            'cagnotte'  => $cagnotte?->reference,
        ]);

        // Nom affiché : "NOM Prénom" ; "Client" si aucun compte lié.
        $nomCotisant  = $user
            ? mb_strtoupper($user->nom) . ' ' . ucfirst(mb_strtolower($user->prenom))
            : 'Client';

        // Le numéro est masqué pour protéger la vie privée (ex : "+241****56").
        $numeroMasque = $user ? $this->maskPhone($user->numero ?? '') : '—';

        // URL cible du QR Code — pointe vers la page de vérification publique du reçu.
        $qrUrl = url('/recu/' . $transId);

        // Logo embarqué en base64 — évite toute requête HTTP externe (isRemoteEnabled=false).
        $logoPath    = resource_path('images/tonji_wordmark.png');
        $logoDataUri = file_exists($logoPath)
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath))
            : null;

        Log::info('ReceiptService::generer — étape QR code', ['qr_url' => $qrUrl]);

        // Data URI base64 du QR Code SVG — embarqué directement dans le PDF.
        $qrDataUri = $this->genererQrDataUri($qrUrl);

        Log::info('ReceiptService::generer — QR généré', [
            'qr_length' => strlen($qrDataUri),
            'qr_prefix' => substr($qrDataUri, 0, 30),
        ]);

        // Tableau de données passé à la vue Blade receipts/paiement.blade.php.
        $data = [
            'trans_id'           => $transId,
            'montant_net'        => (int) ($transaction['montant_net']  ?? 0),
            // montant_brut = montant_net + frais (ce que le cotisant a réellement payé).
            'montant_brut'       => (int) ($transaction['montant_brut'] ?? $transaction['montant_net'] ?? 0),
            'frais'              => (int) ($transaction['frais']        ?? 0),
            'date_heure'         => now()->format('d/m/Y à H:i'),
            'canal'              => $canal,
            'cagnotte_titre'     => $cagnotte?->titre     ?? '—',
            'cagnotte_reference' => $cagnotte?->reference ?? '—',
            // Le type est simplifié pour l'affichage : tontine_periodique → "Tontine", sinon "Cotisation".
            'type_cagnotte'      => $cagnotte
                ? ($cagnotte->type === 'tontine_periodique' ? 'Tontine' : 'Cotisation')
                : '—',
            'nom_cotisant'       => $nomCotisant,
            'numero_masque'      => $numeroMasque,
            'qr_url'             => $qrUrl,
            'qr_data_uri'        => $qrDataUri,
            // Logo Tonji en base64 pour l'en-tête du PDF (évite les requêtes HTTP).
            'logo_data_uri'      => $logoDataUri,
        ];

        Log::info('ReceiptService::generer — chargement vue Blade');

        // Rendu PDF format A6 portrait (taille ticket) avec DomPDF.
        $pdf = Pdf::loadView('receipts.paiement', $data)
            ->setPaper('A6', 'portrait')
            ->setOptions([
                'defaultFont'     => 'DejaVu Sans',
                // Désactivé pour éviter les requêtes HTTP externes lors du rendu.
                'isRemoteEnabled' => false,
                'dpi'             => 150,
            ]);

        Log::info('ReceiptService::generer — rendu DomPDF en cours');

        $filename = 'recu-tonji-' . $transId . '.pdf';
        // Dossier public/receipts/ — accessible via URL sans auth.
        $dir      = public_path('receipts');

        Log::info('ReceiptService::generer — dossier cible', [
            'dir'       => $dir,
            'is_dir'    => is_dir($dir),
            'is_writable' => is_writable(dirname($dir)),
        ]);

        if (! is_dir($dir)) {
            $mkdirOk = mkdir($dir, 0755, true);
            Log::info('ReceiptService::generer — mkdir', ['ok' => $mkdirOk, 'dir' => $dir]);
            if (! $mkdirOk) {
                throw new \RuntimeException("Impossible de créer le dossier {$dir}");
            }
        }

        // Purge les anciens reçus avant d'écrire le nouveau.
        $this->nettoyerAnciensRecus($dir);

        // output() déclenche le rendu DomPDF — c'est l'étape la plus risquée.
        $pdfContent = $pdf->output();
        Log::info('ReceiptService::generer — output() ok', ['bytes' => strlen((string) $pdfContent)]);

        $written = file_put_contents($dir . '/' . $filename, $pdfContent);
        if ($written === false) {
            throw new \RuntimeException("Impossible d'écrire le PDF dans {$dir}/{$filename}");
        }

        $url = url('receipts/' . $filename);
        Log::info('ReceiptService::generer — succès', ['url' => $url, 'bytes_written' => $written]);

        return $url;
    }

    /**
     * Reconstruit les données d'un reçu à partir du trans_id (pour page web + regen PDF).
     */
    public function donneesDepuisTransId(string $transId): ?array
    {
        // Jointure directe sur tondo_payin pour éviter de charger les modèles Eloquent
        // (cette méthode est appelée depuis la page publique — performance critique).
        $payin = \Illuminate\Support\Facades\DB::table('tondo_payin as p')
            ->join('tondo_cagnottes as c', 'p.cagnotte_id', '=', 'c.id')
            ->leftJoin('users as u', 'p.user_id', '=', 'u.id') // leftJoin : paiement anonyme possible.
            ->where('p.trans_id', $transId)
            ->where('p.statut', 'succes') // On n'affiche que les paiements confirmés.
            ->select([
                'p.trans_id', 'p.montant', 'p.request', 'p.updated_at', 'p.numero_tel',
                'c.titre as cagnotte_titre', 'c.reference as cagnotte_reference', 'c.type as cagnotte_type',
                'u.nom', 'u.prenom', 'u.numero as user_numero',
            ])
            ->first();

        if (! $payin) {
            return null;
        }

        // Le champ `request` stocke le payload JSON original de l'initiation du paiement ;
        // on en extrait montant_net et canal si présents.
        $req        = is_string($payin->request) ? (json_decode($payin->request, true) ?? []) : ($payin->request ?? []);
        $montantBrut = (int) $payin->montant; // Montant débité au cotisant (frais inclus).
        $montantNet  = (int) ($req['montant_net'] ?? $montantBrut); // Montant reversé à la cagnotte.
        $canal       = ucfirst($req['canal'] ?? 'App mobile');
        // Préférence : numéro du compte Tondo > numéro saisi à l'initiation.
        $numero      = $payin->user_numero ?? $payin->numero_tel ?? '—';

        $nomCotisant = $payin->nom
            ? mb_strtoupper($payin->nom) . ' ' . ucfirst(mb_strtolower($payin->prenom))
            : 'Client';

        $qrUrl = url('/recu/' . $transId);

        return [
            'trans_id'           => $payin->trans_id,
            'montant_net'        => $montantNet,
            'montant_brut'       => $montantBrut,
            // frais = différence brut - net (2 % Paynala + frais opérateur paiement).
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

        $written = file_put_contents($dir . '/' . $filename, $pdf->output());
        if ($written === false) {
            throw new \RuntimeException("Impossible d'écrire le PDF dans {$dir}/{$filename}");
        }

        return url('receipts/' . $filename);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Génère un QR Code SVG encodé en base64 (data URI) prêt à être
     * inséré dans un <img src="…"> dans la vue Blade ou le PDF.
     *
     * @param  string $url  URL cible encodée dans le QR Code.
     * @return string       Data URI "data:image/svg+xml;base64,…".
     */
    public function genererQrDataUri(string $url): string
    {
        $opts = new QROptions();
        // v6 : outputInterface remplace outputType (QRMarkupSVG::class = format SVG).
        $opts->outputInterface = \chillerlan\QRCode\Output\QRMarkupSVG::class;
        $opts->scale           = 5;    // Taille d'un module (pixel QR) — lisible à toutes les tailles.
        // v6 : outputBase64 remplace imageBase64 — retourne un data URI "data:image/svg+xml;base64,…".
        $opts->outputBase64    = true;

        return (new QRCode($opts))->render($url);
    }

    /**
     * Supprime les fichiers PDF de reçus de plus de 24 heures.
     *
     * Les reçus sont temporaires : l'URL permanente pointe vers la page web
     * /recu/{transId} qui régénère le PDF à la demande si nécessaire.
     *
     * @param string $dir  Chemin absolu du dossier public/receipts/.
     */
    private function nettoyerAnciensRecus(string $dir): void
    {
        $limite = time() - 86400; // Timestamp limite = maintenant - 24 heures.
        foreach (glob($dir . '/recu-tonji-*.pdf') ?: [] as $fichier) {
            if (filemtime($fichier) < $limite) {
                // Suppression silencieuse : un échec ici n'est pas bloquant.
                @unlink($fichier);
            }
        }
    }

    /**
     * Masque un numéro de téléphone pour l'affichage (vie privée).
     *
     * Exemples :
     *   "+24177123456" → "+241*****56"
     *   "0741234567"   → "0*******67"
     *
     * Les 2 derniers chiffres et le préfixe (indicatif + début) restent visibles.
     *
     * @param  string $phone  Numéro E.164 ou local, peut contenir des espaces/tirets.
     * @return string         Numéro masqué avec des astérisques.
     */
    public function maskPhone(string $phone): string
    {
        // On ne conserve que les chiffres et le "+" initial.
        $clean = preg_replace('/[^\d+]/', '', $phone);
        // Trop court pour masquer intelligemment → retourner tel quel.
        if (strlen($clean) < 6) return $clean;
        // Préfixe = tout sauf les 6 derniers caractères.
        $prefix = substr($clean, 0, strlen($clean) - 6);
        // Remplace les chiffres du milieu par des "*", sauf les 2 derniers.
        return $prefix . str_repeat('*', strlen($clean) - strlen($prefix) - 2) . substr($clean, -2);
    }
}
