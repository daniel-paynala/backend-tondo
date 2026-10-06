<?php

namespace App\Services;

use App\Support\MessagePaiementMarchand;
use App\Support\Registre;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

/**
 * Reçus des paiements marchands — boucle distincte de {@see ReceiptService}.
 *
 * Volontairement séparée, sur décision de Daniel, pour deux raisons :
 *  1. ne pas toucher au reçu de cotisation, qui marche et part déjà par
 *     WhatsApp ;
 *  2. pouvoir faire évoluer le fond et la forme d'un reçu commercial sans
 *     tordre un gabarit conçu pour autre chose.
 *
 * Ce qui est partagé est délibérément bas : la fabrique de QR et le masquage
 * de numéro, empruntés à {@see ReceiptService}. Dupliquer cette plomberie
 * aurait donné deux générateurs de QR à maintenir pour aucun bénéfice.
 *
 * Les deux reçus n'ont d'ailleurs pas le même sens. Une cotisation est une
 * ENTRÉE ; un paiement marchand est une SORTIE, et les rôles sont inversés —
 * c'est l'enseigne qui encaisse.
 *
 * Le montant imprimé est celui de la transaction, tel qu'il est parti. Les
 * frais sont traités par l'opérateur dans la transaction elle-même : Tonji n'en
 * calcule aucun et sa réponse n'en détaille aucun.
 */
class ReceiptMarchandService
{
    public function __construct(private readonly ReceiptService $plomberie) {}

    /**
     * Données du reçu, ou null si la référence est inconnue, non confirmée, ou
     * ne désigne pas un paiement marchand.
     *
     * Le filtre sur `marchand_id` n'est pas cosmétique : sans lui, cette
     * boucle servirait aussi les transferts vers un particulier, avec un
     * gabarit qui parlerait d'enseigne là où il n'y en a pas.
     *
     * @return array<string, mixed>|null
     */
    public function donnees(string $transId): ?array
    {
        $payout    = project_table('payout');
        $marchands = project_table('marchands');
        $cagnottes = project_table('cagnottes');

        $ligne = DB::table("{$payout} as p")
            ->join("{$marchands} as m", 'm.id', '=', 'p.marchand_id')
            ->join("{$cagnottes} as c", 'c.id', '=', 'p.cagnotte_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.trans_id', $transId)
            // Un reçu n'existe que pour un paiement confirmé : afficher une
            // sortie en cours laisserait croire que l'argent est arrivé.
            ->where('p.statut', 'succes')
            ->whereNotNull('p.marchand_id')
            ->first([
                'p.trans_id', 'p.montant', 'p.date_creation', 'p.operateur_id',
                'm.nom as marchand_nom', 'm.code_marchand', 'm.numero_tel as marchand_numero',
                'm.titulaire as marchand_titulaire', 'm.ville as marchand_ville',
                'c.titre as cagnotte_titre', 'c.reference as cagnotte_reference',
                'u.nom as payeur_nom', 'u.prenom as payeur_prenom', 'u.numero as payeur_numero',
            ]);

        if (! $ligne) {
            return null;
        }

        $url = url('/recu-marchand/' . $ligne->trans_id);

        return [
            'trans_id'            => $ligne->trans_id,
            // Référence montrée en grand : c'est elle qui circule dans les SMS
            // et que le marchand recopiera pour une réclamation.
            'reference'           => Registre::court($ligne->trans_id) ?? $ligne->trans_id,
            'montant'             => (int) $ligne->montant,
            'montant_affiche'     => MessagePaiementMarchand::montant((int) $ligne->montant),
            'date_heure'          => MessagePaiementMarchand::dateHeure($ligne->date_creation),
            'marchand_nom'        => $ligne->marchand_nom,
            'marchand_code'       => $ligne->code_marchand,
            'marchand_ville'      => $ligne->marchand_ville,
            'marchand_titulaire'  => $ligne->marchand_titulaire,
            'marchand_numero'     => $this->plomberie->maskPhone((string) $ligne->marchand_numero),
            'cagnotte_titre'      => $ligne->cagnotte_titre,
            'cagnotte_reference'  => $ligne->cagnotte_reference,
            'payeur'              => $this->nomPayeur($ligne),
            'payeur_numero'       => $ligne->payeur_numero
                ? $this->plomberie->maskPhone((string) $ligne->payeur_numero)
                : null,
            // Identifiant opérateur : la seule preuve que l'argent a bougé
            // chez Airtel, et ce qu'un service client demandera en premier.
            'operateur_id'        => $ligne->operateur_id,
            'qr_url'              => $url,
            'qr_data_uri'         => $this->plomberie->genererQrDataUri($url),
            'logo_data_uri'       => $this->logo(),
        ];
    }

    /** PDF rendu en mémoire, sans écriture disque. */
    public function pdf(string $transId): ?string
    {
        $donnees = $this->donnees($transId);

        if ($donnees === null) {
            return null;
        }

        return Pdf::loadView('receipts.marchand.pdf', $donnees)
            // A5 et non A4 : le contenu tient dans la moitié d'une A4, et
            // les deux tiers restants faisaient document inachevé — sans
            // compter le papier gâché à l'impression. A5 est aussi le format
            // du reçu de cotisation à un cran près (A6, taille ticket).
            ->setPaper('A5', 'portrait')
            ->setOptions([
                // Sans cette ligne, DomPDF embarque SA police par défaut EN
                // PLUS de celle demandée par la feuille de style : le fichier
                // complet de chaque famille est inclus, et le reçu pesait
                // 915 Ko au lieu de 70. Le reçu de cotisation fixe la même.
                'defaultFont'     => 'DejaVu Sans',
                // Aucune requête sortante pendant le rendu : tout ce que le
                // gabarit affiche est déjà embarqué en data URI.
                'isRemoteEnabled' => false,
            ])
            ->output();
    }

    /**
     * Logo embarqué en base64.
     *
     * Les `*.png` sont routés vers Next.js par nginx : une balise `img` vers
     * un fichier statique ne serait servie ni dans le PDF ni dans la page.
     */
    private function logo(): ?string
    {
        $chemin = resource_path('images/tonji_wordmark.png');

        return file_exists($chemin)
            ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($chemin))
            : null;
    }

    /** Le payeur peut ne pas avoir de compte Tonji : on ne laisse pas vide. */
    private function nomPayeur(object $l): string
    {
        $nom = trim(mb_strtoupper((string) ($l->payeur_nom ?? '')) . ' '
            . ucfirst(mb_strtolower((string) ($l->payeur_prenom ?? ''))));

        return $nom === '' ? 'Client Tonji' : $nom;
    }
}
