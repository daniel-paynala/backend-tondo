<?php

namespace App\Services\WhatsApp;

use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use App\Services\SortieArgent;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gestion des cagnottes et tontines existantes via le canal WhatsApp.
 *
 * Expose trois fonctionnalités principales :
 *   1. Consultation des cagnottes gérées par un utilisateur et de leur historique.
 *   2. Génération d'un PDF récapitulatif des transactions (DomPDF).
 *   3. Sortie d'argent vers un bénéficiaire ou un commerce.
 *
 * La sortie d'argent elle-même n'est PAS écrite ici : elle est déléguée à
 * {@see \App\Services\SortieArgent}, le point d'entrée unique partagé avec
 * l'app et le web. Le bot avait sa propre copie, qui avait silencieusement
 * divergé — notamment en ignorant le verrou des sorties.
 */
class GererCagnotteService
{
    public function __construct(
        private readonly SortieArgent $sortie,
    ) {}

    /**
     * Retourne les cagnottes actives gérées par un utilisateur (non clôturées).
     *
     * Seules les cagnottes dont l'utilisateur est le créateur (user_id) sont
     * retournées. Les cagnottes clôturées sont exclues pour alléger la liste.
     *
     * @param  TondoUser $user  Gérant dont on veut la liste
     * @return Collection<int, TondoCagnotte>  Triées par date de création décroissante
     */
    public function cagnottesGerees(TondoUser $user): Collection
    {
        return TondoCagnotte::where('user_id', $user->id)
            ->where('statut', '!=', 'cloturee')   // exclure les cagnottes fermées
            ->orderBy('date_creation', 'desc')
            ->get();
    }

    /**
     * Retourne l'historique des paiements confirmés pour une cagnotte.
     *
     * Jointure gauche sur la table 'users' pour récupérer le nom du cotisant.
     * La jointure est LEFT JOIN car l'utilisateur peut avoir été supprimé ;
     * dans ce cas, COALESCE retourne 'Client' par défaut.
     * Seuls les paiements avec statut 'succes' sont inclus.
     *
     * @param  TondoCagnotte $cagnotte  Cagnotte dont on veut l'historique
     * @return Collection<int, object>  Champs : trans_id, montant, numero_tel, updated_at, cotisant
     */
    public function historiquePaiements(TondoCagnotte $cagnotte): Collection
    {
        return DB::table(project_table('payin').' as p')
            ->leftJoin('users as u', 'p.user_id', '=', 'u.id')   // LEFT JOIN : l'user peut ne plus exister
            ->where('p.cagnotte_id', $cagnotte->id)
            ->where('p.statut', 'succes')   // uniquement les paiements confirmés
            ->orderBy('p.updated_at', 'desc')   // les plus récents en premier
            ->select([
                'p.trans_id',
                'p.montant',
                'p.numero_tel',
                'p.updated_at',
                DB::raw("COALESCE(u.nom || ' ' || u.prenom, 'Client') as cotisant"),
            ])
            ->get();
    }

    /**
     * Génère un PDF récapitulatif de l'historique des paiements et retourne son URL publique.
     *
     * Utilise DomPDF (barryvdh/laravel-dompdf) avec le template 'receipts.historique'.
     * Le fichier est sauvegardé dans public/receipts/ avec un nom unique basé sur
     * la référence de la cagnotte et la date du jour.
     * Le répertoire est créé automatiquement s'il n'existe pas.
     *
     * @param  TondoCagnotte $cagnotte  Cagnotte dont on génère l'historique
     * @return string                   URL publique du PDF (ex : https://exemple.ga/receipts/xxx.pdf)
     */
    public function genererHistoriquePdf(TondoCagnotte $cagnotte): string
    {
        $paiements = $this->historiquePaiements($cagnotte);
        $total     = (int) $paiements->sum('montant');

        $pdf = Pdf::loadView('receipts.historique', [
            'cagnotte'  => $cagnotte,
            'paiements' => $paiements,
            'total'     => $total,
            'date'      => now()->format('d/m/Y à H:i'),
        ])
            ->setPaper('A6', 'portrait')   // format compact adapté à un reçu
            ->setOptions([
                'defaultFont'     => 'DejaVu Sans',
                'isRemoteEnabled' => false,   // pas de ressources distantes (sécurité)
                'dpi'             => 150,
            ]);

        // Nom de fichier unique par cagnotte et par jour (évite les doublons quotidiens)
        $filename = 'historique-' . $cagnotte->reference . '-' . now()->format('Ymd') . '.pdf';
        $dir      = public_path('receipts');

        // Créer le dossier si inexistant (première génération)
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir . '/' . $filename, $pdf->output());

        // Retourner l'URL publique pour l'envoyer en pièce jointe WhatsApp
        return url('receipts/' . $filename);
    }

    /**
     * Fait sortir de l'argent d'une collecte depuis WhatsApp.
     *
     * **Enveloppe, et non implémentation.** Ce service recopiait son propre
     * décaissement, et cette copie avait dérivé : elle ne consultait pas le
     * verrou des sorties, ne restaurait pas le solde quand l'opérateur refusait,
     * n'alertait aucun administrateur et ne savait pas payer un commerce. Tout
     * cela est désormais celui de {@see SortieArgent}, le même que l'app et le
     * web — ce qui reste ici n'est que la traduction du résultat en exception,
     * forme attendue par les écrans du bot.
     *
     * @param  TondoCagnotte $cagnotte   Collecte débitée.
     * @param  TondoUser     $gerant     Gérant à l'origine de l'opération (trace).
     * @param  string        $numeroE164 Bénéficiaire. Ignoré pour un commerce :
     *                                   c'est sa fiche qui porte le numéro
     *                                   encaisseur.
     * @param  int           $montant    FCFA.
     * @param  object|null   $marchand   Fiche marchande pour un paiement
     *                                   commerce (id, nom, numero_tel,
     *                                   type_paynala), null pour un transfert.
     * @return array{trans_id: string, montant: int, numero: string}
     *
     * @throws \RuntimeException Message destiné à l'écran : verrou posé, solde
     *                           insuffisant, refus de l'opérateur, issue inconnue.
     */
    public function initierReversement(
        TondoCagnotte $cagnotte,
        TondoUser $gerant,
        string $numeroE164,
        int $montant,
        ?object $marchand = null,
    ): array {
        $resultat = $this->sortie->executer(
            cagnotte:   $cagnotte,
            montant:    $montant,
            canal:      'whatsapp',
            numeroE164: $numeroE164,
            marchand:   $marchand,
            // Qui a lancé l'opération, pour l'audit : le bot n'a pas de session
            // authentifiée comme l'API, c'est la seule trace du gérant.
            trace:      ['gerant_id' => $gerant->id],
        );

        if (! $resultat['ok']) {
            throw new \RuntimeException($resultat['message'] ?? 'Transfert impossible.');
        }

        return [
            'trans_id' => $resultat['trans_id'],
            'montant'  => $resultat['montant'],
            'numero'   => $resultat['numero'],
        ];
    }
}
