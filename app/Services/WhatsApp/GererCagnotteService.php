<?php

namespace App\Services\WhatsApp;

use App\Models\TondoCagnotte;
use App\Models\TondoUser;
use App\Services\PaynalaPaymentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gestion des cagnottes et tontines existantes via le canal WhatsApp.
 *
 * Expose trois fonctionnalités principales :
 *   1. Consultation des cagnottes gérées par un utilisateur et de leur historique.
 *   2. Génération d'un PDF récapitulatif des transactions (DomPDF).
 *   3. Initiation d'un reversement (payout) vers le bénéficiaire via Paynala.
 *
 * Le reversement suit un protocole en 3 phases pour garantir la cohérence
 * même en cas d'erreur pendant l'appel externe à Paynala.
 */
class GererCagnotteService
{
    public function __construct(
        private readonly PaynalaPaymentService $paynala,
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
        return DB::table('tondo_payin as p')
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
     * Initie un reversement (payout) depuis une cagnotte vers un bénéficiaire.
     *
     * Protocole en 3 phases pour garantir la cohérence base/opérateur :
     *
     * Phase 1 — Réservation atomique (dans une transaction DB) :
     *   - Verrouille la ligne tondo_cagnottes (lockForUpdate) pour éviter les doubles dépenses.
     *   - Vérifie que le solde disponible couvre le montant demandé.
     *   - Insère une ligne tondo_payout avec statut 'initie' (idempotency key = TONDO-WA-XXXX).
     *   - Décrémente montant_collecte de la cagnotte.
     *
     * Phase 2 — Appel Paynala HORS transaction (pour ne pas bloquer la DB pendant l'appel réseau) :
     *   - Appelle PaynalaPaymentService::disburse().
     *   - En cas d'échec : marque le payout 'echec', log une alerte CRITICAL
     *     (intervention manuelle nécessaire car le solde a déjà été décrémenté).
     *
     * Phase 3 — Confirmation (si Phase 2 réussit) :
     *   - Met à jour le payout à 'succes' avec l'identifiant opérateur retourné.
     *
     * @param  TondoCagnotte $cagnotte   Cagnotte source du reversement
     * @param  TondoUser     $gerant     Gérant initiant le reversement (pour audit)
     * @param  string        $numeroE164 Numéro bénéficiaire au format E.164
     * @param  int           $montant    Montant à reverser (FCFA, doit être ≤ montant_collecte)
     * @return array{trans_id: string, montant: int, numero: string}
     *
     * @throws \RuntimeException Si le solde est insuffisant ou si Paynala échoue
     */
    public function initierReversement(
        TondoCagnotte $cagnotte,
        TondoUser $gerant,
        string $numeroE164,
        int $montant,
    ): array {
        // Convertir E.164 en format local Airtel (0XXXXXXXX) requis par l'API
        $msisdnLocal    = str_starts_with($numeroE164, '+241')
            ? '0' . substr($numeroE164, 4)   // supprime le préfixe +241, ajoute 0
            : ltrim($numeroE164, '+');

        // Clé d'idempotence basée sur le numéro de séquence des payouts (TONDO-WA-0001…)
        $nextNum        = DB::table('tondo_payout')->count() + 1;
        $reference      = 'TONDODISBURSEMENT' . now()->getTimestampMs();
        $idempotencyKey = 'TONDO-WA-' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
        $payoutId       = (string) Str::uuid();
        $transId        = 'TONDOPAYOUT' . strtoupper(Str::random(9));

        // Rechercher le compte bénéficiaire pour renseigner user_id et type_client
        $benefUser = DB::table('users')
            ->where('numero', $numeroE164)
            ->select(['id', 'type_client'])
            ->first();

        // ── Phase 1 — réserver sous row-lock ─────────────────────────────────
        DB::transaction(function () use (
            $cagnotte, $montant, $payoutId, $transId, $idempotencyKey,
            $reference, $numeroE164, $benefUser
        ) {
            // Verrouiller la ligne pour empêcher deux reversements simultanés
            $solde = DB::table('tondo_cagnottes')
                ->where('id', $cagnotte->id)
                ->lockForUpdate()
                ->value('montant_collecte');

            if ((int) $solde < $montant) {
                throw new \RuntimeException(
                    'Solde insuffisant. Disponible : '
                    . number_format((int) $solde, 0, ',', ' ') . ' FCFA.'
                );
            }

            // Insérer le payout avec statut 'initie' avant tout appel externe
            DB::table('tondo_payout')->insert([
                'id'            => $payoutId,
                'project_id'    => $cagnotte->project_id,
                'cagnotte_id'   => $cagnotte->id,
                'user_id'       => $benefUser?->id,
                'trans_id'      => $transId,
                'operateur_id'  => null,   // sera renseigné après confirmation Paynala
                'numero_tel'    => $numeroE164,
                'montant'       => $montant,
                'statut'        => 'initie',
                'request'       => json_encode([
                    'idempotency_key'     => $idempotencyKey,
                    'reference'           => $reference,
                    'numero_beneficiaire' => $numeroE164,
                    'montant'             => $montant,
                    'canal'               => 'whatsapp',
                ]),
                'date_creation' => now(),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            // Décrémenter le solde de la cagnotte (montant réservé)
            DB::table('tondo_cagnottes')
                ->where('id', $cagnotte->id)
                ->update([
                    'montant_collecte' => DB::raw('montant_collecte - ' . $montant),
                    'updated_at'       => now(),
                ]);
        });

        // ── Phase 2 — appel Paynala (hors transaction pour ne pas bloquer la DB) ──
        $disburseType = $this->paynala->resolveDisburseType(
            msisdnLocal: $msisdnLocal,
            msisdnE164:  $numeroE164,
            userId:      $benefUser?->id,
        );

        try {
            $disburseData = $this->paynala->disburse(
                idempotencyKey: $idempotencyKey,
                amount:         $montant,
                msisdn:         $msisdnLocal,   // format local requis par Airtel
                reference:      $reference,
                type:           $disburseType,
            );
        } catch (\RuntimeException $e) {
            // Échec Paynala : marquer 'echec' en DB
            // ATTENTION : le solde a déjà été décrémenté — intervention manuelle requise
            DB::table('tondo_payout')->where('id', $payoutId)->update([
                'statut'     => 'echec',
                'response'   => json_encode(['error' => $e->getMessage()]),
                'updated_at' => now(),
            ]);
            Log::critical('[gerer/reversement] échec Paynala — INTERVENTION MANUELLE REQUISE', [
                'payout_id' => $payoutId,
                'trans_id'  => $transId,
                'montant'   => $montant,
                'beneficiaire' => $numeroE164,
            ]);
            throw $e;
        }

        // ── Phase 3 — confirmer le succès ─────────────────────────────────────
        DB::table('tondo_payout')->where('id', $payoutId)->update([
            'statut'       => 'succes',
            'operateur_id' => $disburseData['airtel_money_id'] ?? null,   // ID retourné par Airtel
            'response'     => json_encode($disburseData),
            'updated_at'   => now(),
        ]);

        return [
            'trans_id' => $transId,
            'montant'  => $montant,
            'numero'   => $numeroE164,
        ];
    }
}
