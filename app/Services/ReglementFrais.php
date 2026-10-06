<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Support\Registre;

/**
 * Journal et règlement des parts de frais.
 *
 * ── Pourquoi un journal plutôt qu'un décaissement immédiat ──────────────────
 *
 * L'API de décaissement n'accepte qu'un `msisdn` par appel et impose un montant
 * plancher. 1 % de 3 000 FCFA fait 30 FCFA : cette part **ne peut pas partir
 * seule**. Elle est donc enregistrée comme due, s'accumule, et part dès que le
 * cumul du compte passe le seuil. Sans journal, ces francs disparaîtraient.
 *
 * ── L'ordre n'est pas négociable ────────────────────────────────────────────
 *
 * Le reversement principal d'abord, les parts ensuite. **Jamais l'inverse.**
 * L'opération du client réussit ou échoue sur le seul reversement principal ;
 * les parts sont une dette que nous nous devons à nous-mêmes. Une part qui
 * échoue ne doit pas faire échouer le transfert du client, et surtout ne doit
 * jamais se « régler » en reprenant son argent.
 *
 * C'est pour cela que {@see enregistrer()} n'échoue jamais bruyamment : l'argent
 * du client est déjà parti quand elle est appelée. Au pire la part est perdue
 * pour nous, ce qui est un incident comptable — pas un incident client.
 *
 * ── Les quatre états d'une part ─────────────────────────────────────────────
 *
 *   'du'        enregistrée, pas encore décaissée (souvent sous le plancher)
 *   'regle'     décaissée avec succès
 *   'echec'     refus AFFIRMÉ de l'opérateur — la part reste à notre charge
 *   'incertain' issue inconnue — on ne réessaie PAS tout seul, relancer
 *               pourrait payer deux fois la même part
 *
 * Même règle que pour l'argent du client : dans le doute, on n'agit pas.
 */
class ReglementFrais
{
    public function __construct(private readonly PaynalaPaymentService $paynala) {}

    /**
     * Montant minimum accepté par l'opérateur pour un décaissement.
     *
     * En réglage : c'est une contrainte de l'opérateur, pas une règle Tonji, et
     * elle changera sans nous prévenir.
     */
    private function plancher(): int
    {
        return (int) config('services.paynala.montant_minimum_disburse', 100);
    }

    /**
     * Inscrit les parts d'un reversement au journal.
     *
     * Appelée APRÈS le succès du reversement principal. Ne lève jamais : à ce
     * stade l'argent du client est parti, et faire remonter une erreur ferait
     * croire à un transfert raté.
     *
     * @param array<int, array{compte_id: string, montant: int, libelle: string}> $parts
     */
    public function enregistrer(array $parts, string $projectId, string $payoutId, string $cagnotteId): void
    {
        if ($parts === []) {
            return;
        }

        try {
            DB::table(project_table('frais_dus'))->insert(array_map(
                fn (array $part) => [
                    'id'          => (string) Str::uuid(),
                    'project_id'  => $projectId,
                    'compte_id'   => $part['compte_id'],
                    'payout_id'   => $payoutId,
                    'cagnotte_id' => $cagnotteId,
                    'montant'     => $part['montant'],
                    'statut'      => 'du',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ],
                $parts,
            ));
        } catch (\Throwable $e) {
            // Perdre la trace d'une part est un incident comptable : il nous
            // coûte ce prélèvement, il ne coûte rien au client. D'où un log
            // CRITICAL et pas une exception.
            Log::critical('[frais] parts non enregistrées — prélèvement perdu', [
                'payout_id' => $payoutId,
                'parts'     => $parts,
                'erreur'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Règle ce qui est dû à un compte, si le cumul atteint le plancher.
     *
     * @return array{regle: bool, montant: int, trans_id: ?string, raison: ?string}
     */
    public function reglerCompte(object $compte, string $projectId): array
    {
        $plancher = $this->plancher();
        $table    = project_table('frais_dus');

        // Lecture avant prise : inutile de réserver des lignes qu'on relâcherait
        // aussitôt parce que le cumul est trop faible.
        $cumul = (int) DB::table($table)
            ->where('project_id', $projectId)
            ->where('compte_id', $compte->id)
            ->where('statut', 'du')
            ->whereNull('trans_id')
            ->sum('montant');

        if ($cumul < $plancher) {
            return ['regle' => false, 'montant' => $cumul, 'trans_id' => null,
                    'raison' => 'cumul sous le plancher'];
        }

        // ── Prise atomique ───────────────────────────────────────────────────
        //
        // Poser la référence sur les lignes libres les retire de la vue des
        // autres passages (`whereNull('trans_id')`). Deux exécutions
        // concurrentes ne peuvent donc pas décaisser deux fois les mêmes parts :
        // la seconde prend zéro ligne.
        $transId = Registre::nouvelleReference('frais_dispatch');

        $prises = DB::table($table)
            ->where('project_id', $projectId)
            ->where('compte_id', $compte->id)
            ->where('statut', 'du')
            ->whereNull('trans_id')
            ->update(['trans_id' => $transId, 'updated_at' => now()]);

        if ($prises === 0) {
            return ['regle' => false, 'montant' => 0, 'trans_id' => null,
                    'raison' => 'parts déjà prises par un autre passage'];
        }

        // Le montant décaissé est celui des lignes RÉELLEMENT prises, pas le
        // cumul lu plus haut : une part a pu s'ajouter entre les deux.
        $montant = (int) DB::table($table)->where('trans_id', $transId)->sum('montant');

        $msisdnLocal = str_starts_with($compte->numero_tel, '+241')
            ? '0' . substr($compte->numero_tel, 4)
            : ltrim($compte->numero_tel, '+');

        $reference = Registre::PREFIXES['reference_decaissement'] . now()->getTimestampMs();

        try {
            $reponse = $this->paynala->disburse(
                idempotencyKey: $transId,
                amount:         $montant,
                msisdn:         $msisdnLocal,
                reference:      $reference,
                type:           PaynalaPaymentService::modeDisburse($compte->type_paynala),
            );
        } catch (DecaissementRefuse $e) {
            // Refus AFFIRMÉ : rien n'est parti. Les parts redeviennent dues ?
            // Non — un refus se répéterait à l'identique à chaque passage et
            // réessaierait indéfiniment. On les marque `echec` : la part reste
            // à notre charge et quelqu'un doit regarder pourquoi.
            DB::table($table)->where('trans_id', $transId)->update([
                'statut'     => 'echec',
                'response'   => json_encode(['error' => $e->getMessage()]),
                'updated_at' => now(),
            ]);

            Log::warning('[frais] règlement refusé', [
                'compte'  => $compte->libelle,
                'montant' => $montant,
                'erreur'  => $e->getMessage(),
            ]);

            return ['regle' => false, 'montant' => $montant, 'trans_id' => $transId,
                    'raison' => 'refusé : ' . $e->getMessage()];
        } catch (ConnectionException | \RuntimeException $e) {
            // Issue inconnue : la part a peut-être été versée. On ne réessaie
            // PAS — relancer paierait potentiellement deux fois. Même règle que
            // pour l'argent du client.
            DB::table($table)->where('trans_id', $transId)->update([
                'statut'     => 'incertain',
                'response'   => json_encode(['error' => $e->getMessage(), 'issue' => 'inconnue']),
                'updated_at' => now(),
            ]);

            Log::critical('[frais] issue inconnue — règlement à vérifier avant toute relance', [
                'compte'   => $compte->libelle,
                'montant'  => $montant,
                'trans_id' => $transId,
                'erreur'   => $e->getMessage(),
            ]);

            return ['regle' => false, 'montant' => $montant, 'trans_id' => $transId,
                    'raison' => 'issue inconnue : ' . $e->getMessage()];
        }

        DB::table($table)->where('trans_id', $transId)->update([
            'statut'     => 'regle',
            'response'   => json_encode($reponse),
            'regle_at'   => now(),
            'updated_at' => now(),
        ]);

        return ['regle' => true, 'montant' => $montant, 'trans_id' => $transId, 'raison' => null];
    }

    /**
     * Tente de régler tout de suite les comptes concernés par ces parts.
     *
     * Appelée juste après un reversement : pour un gros montant, la part passe
     * le plancher seule et part immédiatement — « au moment du reversement
     * principal », comme voulu. Pour un petit montant elle s'accumule et c'est
     * la tâche planifiée qui la réglera.
     *
     * N'échoue jamais : l'argent du client est déjà parti.
     *
     * @param array<int, array{compte_id: string}> $parts
     */
    public function tenterReglementImmediat(array $parts, string $projectId): void
    {
        foreach (array_unique(array_column($parts, 'compte_id')) as $compteId) {
            try {
                $compte = DB::table(project_table('frais_comptes'))
                    ->where('id', $compteId)
                    ->first(['id', 'libelle', 'numero_tel', 'type_paynala']);

                if ($compte !== null) {
                    $this->reglerCompte($compte, $projectId);
                }
            } catch (\Throwable $e) {
                Log::error('[frais] règlement immédiat impossible — la part reste due', [
                    'compte_id' => $compteId,
                    'erreur'    => $e->getMessage(),
                ]);
            }
        }
    }
}
