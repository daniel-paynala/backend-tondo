<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TondoCagnotte;
use App\Services\WhatsApp\CotisationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Réconciliation financière par cagnotte.
 *
 * Compare le solde courant (`montant_collecte`) avec la somme calculée depuis
 * les tables de transactions. Tout écart indique un bug ou une manipulation
 * directe de la base.
 *
 * GET /api/admin/cagnottes/{reference}/reconcile
 */
class ReconciliationController extends Controller
{
    /**
     * GET /api/admin/cagnottes/{reference}/reconcile
     *
     * Réconciliation financière d'une cagnotte spécifique.
     * Compare `montant_collecte` (colonne dénormalisée) avec le calcul
     * depuis les tables sources : SUM(payin succes) - SUM(payout succes).
     *
     * Un écart non nul signale :
     *  - un bug dans la logique de crédit/débit
     *  - une manipulation directe de la base de données
     *  - une transaction restée en statut 'initie' trop longtemps
     *
     * @param string $reference Référence numérique à 6 chiffres de la cagnotte
     * @return JsonResponse {
     *   reference, titre, solde_actuel, solde_attendu, ecart, is_ok,
     *   total_payin_succes, total_payout_succes,
     *   payouts_initie_anciens, payins_initie_anciens
     * }
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $cagnotte = TondoCagnotte::where('reference', $reference)->first();

        if (! $cagnotte) {
            return response()->json(['message' => 'Cagnotte introuvable.'], 404);
        }

        // Total collecté depuis les payins confirmés (cotisations reçues).
        $totalPayin = (int) DB::table(project_table('payin'))
            ->where('cagnotte_id', $cagnotte->id)
            ->where('statut', 'succes')
            ->sum('montant');

        // Total décaissé depuis les payouts confirmés (reversements effectués).
        $totalPayout = (int) DB::table(project_table('payout'))
            ->where('cagnotte_id', $cagnotte->id)
            ->where('statut', 'succes')
            ->sum('montant');

        // Payouts bloqués en statut 'initie' depuis plus de 15 minutes
        // (fenêtre API Paynala) — suspects : Paynala a peut-être répondu mais
        // le backend a planté entre la phase 2 et la phase 3.
        $payoutsInitieAnciens = DB::table(project_table('payout'))
            ->where('cagnotte_id', $cagnotte->id)
            ->where('statut', 'initie')
            ->where('date_creation', '<', now()->subMinutes(15))
            ->get(['id', 'trans_id', 'montant', 'numero_tel', 'date_creation']);

        // Payins initiés depuis plus de 10 minutes — le mobile a probablement
        // arrêté de poller. À investiguer manuellement si le montant est élevé.
        $payinsInitieAnciens = DB::table(project_table('payin'))
            ->where('cagnotte_id', $cagnotte->id)
            ->where('statut', 'initie')
            ->where('date_creation', '<', now()->subMinutes(10))
            ->get(['id', 'trans_id', 'montant', 'numero_tel', 'date_creation']);

        // Calcul de l'écart : positif = excédent, négatif = manque.
        $soldeAttendu = $totalPayin - $totalPayout;
        $soldeActuel  = (int) $cagnotte->montant_collecte;
        $ecart        = $soldeActuel - $soldeAttendu;
        $isOk         = $ecart === 0;

        return response()->json([
            'reference'             => $cagnotte->reference,
            'titre'                 => $cagnotte->titre,
            'solde_actuel'          => $soldeActuel,
            'solde_attendu'         => $soldeAttendu,
            'ecart'                 => $ecart,
            'is_ok'                 => $isOk,
            'total_payin_succes'    => $totalPayin,
            'total_payout_succes'   => $totalPayout,
            'payouts_initie_anciens' => $payoutsInitieAnciens,
            'payins_initie_anciens'  => $payinsInitieAnciens,
        ]);
    }

    /**
     * GET /api/admin/reconcile
     *
     * Réconciliation globale : liste toutes les cagnottes présentant un écart
     * entre `montant_collecte` et les transactions sources.
     *
     * Utile pour un audit périodique ou pour investiguer un incident de paiement.
     * Charge toutes les cagnottes en mémoire — à optimiser en SQL brut si le
     * volume dépasse quelques milliers.
     *
     * @return JsonResponse { anomalies_count: int, anomalies: array }
     */
    public function index(): JsonResponse
    {
        // Charge uniquement les colonnes nécessaires pour limiter la mémoire.
        $cagnottes = TondoCagnotte::all(['id', 'reference', 'titre', 'montant_collecte']);

        $anomalies = [];

        foreach ($cagnottes as $cagnotte) {
            $totalPayin = (int) DB::table(project_table('payin'))
                ->where('cagnotte_id', $cagnotte->id)
                ->where('statut', 'succes')
                ->sum('montant');

            $totalPayout = (int) DB::table(project_table('payout'))
                ->where('cagnotte_id', $cagnotte->id)
                ->where('statut', 'succes')
                ->sum('montant');

            $soldeAttendu = $totalPayin - $totalPayout;
            $ecart        = (int) $cagnotte->montant_collecte - $soldeAttendu;

            // On n'inclut que les cagnottes avec un écart détecté.
            if ($ecart !== 0) {
                $anomalies[] = [
                    'reference'     => $cagnotte->reference,
                    'titre'         => $cagnotte->titre,
                    'solde_actuel'  => (int) $cagnotte->montant_collecte,
                    'solde_attendu' => $soldeAttendu,
                    'ecart'         => $ecart,
                ];
            }
        }

        return response()->json([
            'anomalies_count' => count($anomalies),
            'anomalies'       => $anomalies,
        ]);
    }

    /**
     * POST /api/admin/cagnottes/{reference}/reconcile/diagnostiquer
     *
     * Diagnostic d'un écart : re-vérifie d'abord les payins `initie` auprès de
     * l'agrégateur (en résout certains), recalcule le solde attendu NET vs NET,
     * détecte les doublons de `paiements`, classe la cause et dit si un correctif
     * SÛR est applicable.
     */
    public function diagnostiquer(Request $request, string $reference): JsonResponse
    {
        $cagnotte = TondoCagnotte::where('reference', $reference)->first();
        if (! $cagnotte) {
            return response()->json(['message' => 'Cagnotte introuvable.'], 404);
        }

        // 1) Re-vérifier les payins encore 'initie' (crédite les vrais SUCCESS).
        $svc = app(CotisationService::class);
        $payinsInitie = DB::table(project_table('payin'))
            ->where('cagnotte_id', $cagnotte->id)
            ->where('statut', 'initie')
            ->get(['trans_id', 'project_id']);
        $revus = 0;
        foreach ($payinsInitie as $p) {
            try {
                $svc->verifierStatut($p->trans_id, $p->project_id);
                $revus++;
            } catch (\Throwable) {
                // best-effort : l'agrégateur peut être indisponible.
            }
        }
        $cagnotte->refresh(); // montant_collecte a pu changer

        // 2) Recalcul NET vs NET + doublons + transactions encore en attente.
        $soldeAttendu = $this->soldeAttenduNet($cagnotte->id);
        $soldeActuel  = (int) $cagnotte->montant_collecte;
        $ecart        = $soldeActuel - $soldeAttendu;
        $nbDoublons   = count($this->doublonsADesactiver($cagnotte->id));
        $pending      = $this->pendingCount($cagnotte->id);

        // 3) Classer la cause + décider si un correctif sûr existe.
        if ($pending > 0) {
            $cause = 'en_attente';        // des payins/payouts encore initie → réessayer
            $corrigeable = false;
        } elseif ($ecart === 0 && $nbDoublons === 0) {
            $cause = 'resolu';            // rien à corriger
            $corrigeable = false;
        } elseif ($ecart > 0) {
            $cause = 'sur_credit';        // solde > attendu (double crédit probable)
            $corrigeable = true;
        } else {
            $cause = 'sous_credit';       // solde < attendu (crédit manqué déjà résolu)
            $corrigeable = true;
        }

        return response()->json([
            'reference'       => $cagnotte->reference,
            'cause'           => $cause,
            'corrigeable'     => $corrigeable,
            'solde_actuel'    => $soldeActuel,
            'solde_attendu'   => $soldeAttendu,
            'ecart'           => $ecart,
            'nb_doublons'     => $nbDoublons,
            'payins_revus'    => $revus,
            'pending'         => $pending,
            'action_proposee' => $corrigeable
                ? "montant_collecte : {$soldeActuel} → {$soldeAttendu} FCFA"
                    . ($nbDoublons > 0 ? " · désactiver {$nbDoublons} doublon(s)" : '')
                : null,
        ]);
    }

    /**
     * POST /api/admin/cagnottes/{reference}/reconcile/corriger
     *
     * Applique la correction (transaction atomique) :
     *  1. désactive les lignes `paiements` en double (motif « doublon »),
     *  2. recalcule `montant_collecte` = solde attendu (net vs net),
     *  3. journalise l'action dans `tonji_logs`.
     * Refusé s'il reste des transactions `initie` (résultat incertain).
     */
    public function corriger(Request $request, string $reference): JsonResponse
    {
        $cagnotte = TondoCagnotte::where('reference', $reference)->first();
        if (! $cagnotte) {
            return response()->json(['message' => 'Cagnotte introuvable.'], 404);
        }

        if ($this->pendingCount($cagnotte->id) > 0) {
            return response()->json([
                'message' => 'Des transactions sont encore en attente. Diagnostiquez à nouveau plus tard.',
            ], 422);
        }

        $admin  = $request->user();
        $resume = ['ancien' => 0, 'nouveau' => 0, 'doublons' => 0];

        DB::transaction(function () use ($cagnotte, $admin, &$resume) {
            // 1) Désactiver les doublons (soft-delete, motif « doublon »).
            $aDesactiver = $this->doublonsADesactiver($cagnotte->id);
            if (! empty($aDesactiver)) {
                DB::table(project_table('paiements'))
                    ->whereIn('id', $aDesactiver)
                    ->update(['actif' => false, 'motif_annulation' => 'doublon']);
            }

            // 2) Recalculer montant_collecte à la valeur canonique.
            $soldeAttendu = $this->soldeAttenduNet($cagnotte->id);
            $ancien       = (int) $cagnotte->montant_collecte;

            DB::table(project_table('cagnottes'))
                ->where('id', $cagnotte->id)
                ->update(['montant_collecte' => $soldeAttendu, 'updated_at' => now()]);

            // 3) Journaliser (audit).
            DB::table(project_table('logs'))->insert([
                'id'              => (string) Str::uuid(),
                'project_id'      => $cagnotte->project_id,
                'acteur_admin_id' => $admin?->id,
                'acteur_libelle'  => $admin ? trim(($admin->prenom ?? '') . ' ' . ($admin->nom ?? '')) : 'Admin',
                'acteur_role'     => 'admin',
                'action'          => 'reconcile_corriger',
                'cible'           => 'cagnotte:' . $cagnotte->reference,
                'niveau'          => 'info',
                'metadonnees'     => json_encode([
                    'montant_collecte_avant' => $ancien,
                    'montant_collecte_apres' => $soldeAttendu,
                    'doublons_desactives'    => count($aDesactiver),
                ]),
                'date'            => now(),
                'created_at'      => now(),
            ]);

            $resume = [
                'ancien'   => $ancien,
                'nouveau'  => $soldeAttendu,
                'doublons' => count($aDesactiver),
            ];
        });

        return response()->json([
            'message'                => 'Correction appliquée.',
            'montant_collecte_avant' => $resume['ancien'],
            'montant_collecte_apres' => $resume['nouveau'],
            'doublons_desactives'    => $resume['doublons'],
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Solde attendu NET : Σ payin.montant_net (succès) − Σ payout (succès). */
    private function soldeAttenduNet(string $cagnotteId): int
    {
        // coalesce(montant_net, montant) : net si présent, sinon brut (legacy).
        $totalPayinNet = (int) DB::table(project_table('payin'))
            ->where('cagnotte_id', $cagnotteId)
            ->where('statut', 'succes')
            ->selectRaw('COALESCE(SUM(COALESCE(montant_net, montant)), 0) AS t')
            ->value('t');

        $totalPayout = (int) DB::table(project_table('payout'))
            ->where('cagnotte_id', $cagnotteId)
            ->where('statut', 'succes')
            ->sum('montant');

        return $totalPayinNet - $totalPayout;
    }

    /** Nombre de transactions encore `initie` (payin + payout). */
    private function pendingCount(string $cagnotteId): int
    {
        $payin = (int) DB::table(project_table('payin'))
            ->where('cagnotte_id', $cagnotteId)->where('statut', 'initie')->count();
        $payout = (int) DB::table(project_table('payout'))
            ->where('cagnotte_id', $cagnotteId)->where('statut', 'initie')->count();

        return $payin + $payout;
    }

    /**
     * IDs des lignes `paiements` ACTIVES à désactiver : pour chaque `trans_id`
     * présent en plusieurs exemplaires, on garde la plus ancienne et on marque
     * les autres comme doublons.
     */
    private function doublonsADesactiver(string $cagnotteId): array
    {
        $lignes = DB::table(project_table('paiements'))
            ->where('cagnotte_id', $cagnotteId)
            ->where('actif', true)
            ->whereNotNull('trans_id')
            ->orderBy('trans_id')
            ->orderBy('created_at')
            ->get(['id', 'trans_id']);

        $vus = [];
        $aDesactiver = [];
        foreach ($lignes as $l) {
            if (isset($vus[$l->trans_id])) {
                $aDesactiver[] = $l->id;   // exemplaire en trop
            } else {
                $vus[$l->trans_id] = true; // premier gardé
            }
        }

        return $aDesactiver;
    }
}
