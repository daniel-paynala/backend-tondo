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
 * ── L'ordre : les commissions d'abord ──────────────────────────────────────
 *
 * Paynala n'accepte qu'un `msisdn` par appel, il faut donc enchaîner les
 * décaissements. Daniel a tranché : **de la plus petite part à la plus grande,
 * les commissions avant le bénéficiaire** (dont la part est la plus grosse par
 * construction).
 *
 * Conséquence à connaître, parce qu'elle est le prix de cet ordre : le
 * transfert principal peut échouer APRÈS que des commissions soient parties.
 * L'argent du client a alors partiellement quitté la collecte alors que son
 * opération a échoué. Il n'y a pas d'annulation possible — l'opérateur ne
 * rembourse pas un décaissement.
 *
 * {@see \App\Services\SortieArgent} en tient compte : sur un refus du
 * transfert principal, il ne restaure que ce qui N'EST PAS parti. Restaurer la
 * totalité recréditerait la collecte de commissions déjà décaissées, et la
 * sortie suivante les dépenserait une seconde fois.
 *
 * {@see enregistrer()} n'échoue jamais bruyamment : perdre la trace d'une part
 * est un incident comptable — il nous coûte ce prélèvement, il ne coûte rien
 * au client.
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
     * Inscrit les parts au journal, puis tente de les régler dans l'ordre.
     *
     * Appelée AVANT le décaissement principal : les commissions d'abord. Les
     * parts arrivent déjà triées de la plus petite à la plus grande par
     * {@see RepartitionFrais}.
     *
     * Une part sous le plancher de l'opérateur ne peut pas partir seule : elle
     * reste `du` et s'accumule. Le règlement porte sur le CUMUL du compte, donc
     * une petite part peut déclencher le départ de tout ce qui attendait.
     *
     * Ne lève jamais : un incident sur une part ne doit pas empêcher le
     * transfert du client. La part reste due, elle repassera.
     *
     * @param  array<int, array{compte_id: string, montant: int, libelle: string}> $parts
     * @return array{sorti: int, parts: array<int, array{compte_id: string, montant: int, statut: string}>}
     *   `sorti` = montant des parts de CETTE transaction qui ont réellement
     *   quitté la caisse — réglées, ou d'issue inconnue (dans le doute on
     *   suppose qu'elles sont parties). C'est ce que l'appelant ne doit PAS
     *   restaurer si son décaissement principal échoue.
     */
    public function enregistrerEtRegler(
        array $parts,
        string $projectId,
        ?string $payoutId,
        string $cagnotteId,
    ): array {
        if ($parts === []) {
            return ['sorti' => 0, 'parts' => []];
        }

        $ids = [];

        // ── Inscription ──────────────────────────────────────────────────────
        try {
            $lignes = [];
            foreach ($parts as $part) {
                $id = (string) Str::uuid();
                $ids[$id] = $part;
                $lignes[] = [
                    'id'          => $id,
                    'project_id'  => $projectId,
                    'compte_id'   => $part['compte_id'],
                    'payout_id'   => $payoutId,
                    'cagnotte_id' => $cagnotteId,
                    'montant'     => $part['montant'],
                    'statut'      => 'du',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
            }

            DB::table(project_table('frais_dus'))->insert($lignes);
        } catch (\Throwable $e) {
            // Sans journal, impossible de régler ni de savoir ce qui est dû.
            // On renonce au dispatch pour cette transaction : le bénéficiaire
            // recevra son net et les commissions seront perdues pour nous. Un
            // incident comptable, pas un incident client.
            Log::critical('[frais] parts non enregistrées — prélèvement perdu', [
                'payout_id' => $payoutId,
                'parts'     => $parts,
                'erreur'    => $e->getMessage(),
            ]);

            return ['sorti' => 0, 'parts' => []];
        }

        // ── Règlement, dans l'ordre reçu ─────────────────────────────────────
        // Un compte par part, et les parts sont déjà triées. Isolé par compte :
        // un compte mal configuré ne doit pas empêcher les autres de partir.
        foreach (array_unique(array_column($parts, 'compte_id')) as $compteId) {
            try {
                $compte = DB::table(project_table('frais_comptes'))
                    ->where('id', $compteId)
                    ->first(['id', 'libelle', 'numero_tel', 'type_paynala']);

                if ($compte !== null) {
                    $this->reglerCompte($compte, $projectId);
                }
            } catch (\Throwable $e) {
                Log::error('[frais] règlement impossible — la part reste due', [
                    'compte_id' => $compteId,
                    'erreur'    => $e->getMessage(),
                ]);
            }
        }

        // ── Ce qui est réellement sorti, pour CETTE transaction ──────────────
        //
        // Relu en base : `reglerCompte()` règle le CUMUL d'un compte, qui peut
        // contenir des parts d'autres transactions. Seules les lignes qu'on
        // vient d'insérer comptent ici.
        //
        // `incertain` est compté comme SORTI. On ne sait pas si l'argent est
        // parti, et c'est précisément pour cela qu'il ne faut pas le restaurer :
        // le recréditer alors qu'il a quitté la caisse le ferait dépenser deux
        // fois. Même règle que partout ailleurs — dans le doute, on ne rend pas.
        $etats = DB::table(project_table('frais_dus'))
            ->whereIn('id', array_keys($ids))
            ->get(['id', 'compte_id', 'montant', 'statut']);

        $sorti = (int) $etats->whereIn('statut', ['regle', 'incertain'])->sum('montant');

        return [
            'sorti' => $sorti,
            'parts' => $etats->map(fn ($e) => [
                'compte_id' => $e->compte_id,
                'montant'   => (int) $e->montant,
                'statut'    => $e->statut,
            ])->all(),
        ];
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
}
