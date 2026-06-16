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

class GererCagnotteService
{
    public function __construct(
        private readonly PaynalaPaymentService $paynala,
    ) {}

    public function cagnottesGerees(TondoUser $user): Collection
    {
        return TondoCagnotte::where('user_id', $user->id)
            ->where('statut', '!=', 'cloturee')
            ->orderBy('date_creation', 'desc')
            ->get();
    }

    public function historiquePaiements(TondoCagnotte $cagnotte): Collection
    {
        return DB::table('tondo_payin as p')
            ->leftJoin('users as u', 'p.user_id', '=', 'u.id')
            ->where('p.cagnotte_id', $cagnotte->id)
            ->where('p.statut', 'succes')
            ->orderBy('p.updated_at', 'desc')
            ->select([
                'p.trans_id',
                'p.montant',
                'p.numero_tel',
                'p.updated_at',
                DB::raw("COALESCE(u.nom || ' ' || u.prenom, 'Client') as cotisant"),
            ])
            ->get();
    }

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
            ->setPaper('A6', 'portrait')
            ->setOptions([
                'defaultFont'     => 'DejaVu Sans',
                'isRemoteEnabled' => false,
                'dpi'             => 150,
            ]);

        $filename = 'historique-' . $cagnotte->reference . '-' . now()->format('Ymd') . '.pdf';
        $dir      = public_path('receipts');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir . '/' . $filename, $pdf->output());

        return url('receipts/' . $filename);
    }

    /**
     * Réserve les fonds, appelle Paynala disburse, confirme.
     * Lance une RuntimeException si le solde est insuffisant ou si Paynala échoue.
     *
     * @return array{trans_id: string, montant: int, numero: string}
     */
    public function initierReversement(
        TondoCagnotte $cagnotte,
        TondoUser $gerant,
        string $numeroE164,
        int $montant,
    ): array {
        $msisdnLocal    = str_starts_with($numeroE164, '+241')
            ? '0' . substr($numeroE164, 4)
            : ltrim($numeroE164, '+');

        $nextNum        = DB::table('tondo_payout')->count() + 1;
        $reference      = 'TONDODISBURSEMENT' . now()->getTimestampMs();
        $idempotencyKey = 'TONDO-WA-' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
        $payoutId       = (string) Str::uuid();
        $transId        = 'TONDOPAYOUT' . strtoupper(Str::random(9));

        $benefUser = DB::table('users')
            ->where('numero', $numeroE164)
            ->select(['id', 'type_client'])
            ->first();

        // Phase 1 — réserver sous row-lock
        DB::transaction(function () use (
            $cagnotte, $montant, $payoutId, $transId, $idempotencyKey,
            $reference, $numeroE164, $benefUser
        ) {
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

            DB::table('tondo_payout')->insert([
                'id'            => $payoutId,
                'project_id'    => $cagnotte->project_id,
                'cagnotte_id'   => $cagnotte->id,
                'user_id'       => $benefUser?->id,
                'trans_id'      => $transId,
                'operateur_id'  => null,
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

            DB::table('tondo_cagnottes')
                ->where('id', $cagnotte->id)
                ->update([
                    'montant_collecte' => DB::raw('montant_collecte - ' . $montant),
                    'updated_at'       => now(),
                ]);
        });

        // Phase 2 — appel Paynala (hors transaction)
        $disburseType = $this->paynala->resolveDisburseType(
            msisdnLocal: $msisdnLocal,
            msisdnE164:  $numeroE164,
            userId:      $benefUser?->id,
        );

        try {
            $disburseData = $this->paynala->disburse(
                idempotencyKey: $idempotencyKey,
                amount:         $montant,
                msisdn:         $msisdnLocal,
                reference:      $reference,
                type:           $disburseType,
            );
        } catch (\RuntimeException $e) {
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

        // Phase 3 — confirmer
        DB::table('tondo_payout')->where('id', $payoutId)->update([
            'statut'       => 'succes',
            'operateur_id' => $disburseData['airtel_money_id'] ?? null,
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
