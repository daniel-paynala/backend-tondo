<?php

namespace App\Services;

use App\Models\TondoCagnotte;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Support\Registre;

/**
 * Sortie d'argent d'une collecte — **le seul chemin**, pour tous les canaux.
 *
 * App, web et WhatsApp écrivaient chacun leur propre décaissement. Trois
 * implémentations veut dire trois comportements : le bot ne consultait pas le
 * verrou des sorties, ne restaurait pas le solde sur un refus de l'opérateur,
 * n'alertait pas les administrateurs et ne savait pas payer un commerce. Rien
 * de tout cela n'était un choix — c'était la dérive mécanique de trois copies.
 *
 * Ce service porte donc tout ce qui touche à l'argent et ne laisse aux appelants
 * que ce qui leur est propre : la validation de leur formulaire et la mise en
 * forme de leur réponse.
 *
 * ── Ordre des opérations ─────────────────────────────────────────────────────
 *   1. verrou · 2. environnement · 3. répartition · 4. réservation du solde
 *   5. **commissions** · 6. décaissement du net au bénéficiaire
 *
 * Les commissions partent AVANT le bénéficiaire : Paynala n'accepte qu'un
 * `msisdn` par appel, il faut enchaîner, et Daniel a voulu les petites parts
 * d'abord. Prix de cet ordre : un refus du décaissement principal survient
 * alors que des commissions sont déjà parties, et elles ne se rattrapent pas.
 * D'où la **restauration partielle** plus bas — on ne rend que ce qui n'a pas
 * quitté la caisse.
 *
 * Le verrou est consulté AVANT toute réservation et avant tout appel externe.
 * C'est le seul contrôle qui compte : une interface peut masquer un bouton,
 * elle ne protège rien. Un verrou posé pendant que le client remplissait son
 * formulaire est donc respecté — il ne découvre le refus qu'à la validation,
 * mais l'argent n'a pas bougé.
 *
 * ── Les trois issues d'un décaissement ───────────────────────────────────────
 *   - **succès** : payout `succes`, solde définitivement amputé.
 *   - **refus explicite** ({@see DecaissementRefuse}) : payout `echec` et solde
 *     RESTAURÉ dans la même transaction. L'argent n'est pas parti, la collecte
 *     le récupère.
 *   - **issue inconnue** (tout le reste) : payout `en_cours`, solde NON
 *     restauré, alerte aux administrateurs, et la ligne remonte dans les
 *     réconciliations à traiter.
 *
 * **Dans le doute, l'argent ne revient pas** (décision de Daniel, 2026-10-06).
 * Les deux erreurs ne coûtent pas pareil :
 *
 *   - restaurer à tort → l'argent est parti ET la collecte l'affiche
 *     disponible : la sortie suivante le dépense une seconde fois. Perte
 *     réelle, irrécupérable, et **rien ne la signale** ;
 *   - ne pas restaurer à tort → le montant attend dans une ligne visible en
 *     réconciliation, réglée à la main en quelques minutes.
 *
 * Le cas se traite avec l'opérateur, pas par supposition.
 */
class SortieArgent
{
    public function __construct(
        private readonly PaynalaPaymentService $paynala,
        private readonly PaiementMarchandNotifier $notifier,
        private readonly SortiesAutorisees $verrou,
        private readonly RepartitionFrais $repartition,
        private readonly ReglementFrais $reglement,
    ) {}

    /**
     * Fait sortir [$montant] de [$cagnotte] vers une personne ou un commerce.
     *
     * @param  TondoCagnotte $cagnotte   Collecte débitée.
     * @param  int           $montant    FCFA, strictement positif.
     * @param  string        $canal      'app', 'web', 'whatsapp' — tracé dans
     *                                   `payout.request.canal`. Surtout PAS
     *                                   dans la colonne `payout.canal`, qui
     *                                   désigne le rail du décaissement et
     *                                   n'accepte que 'mobile_money' ou
     *                                   'especes'.
     * @param  string|null   $numeroE164 Bénéficiaire, quand ce n'est pas un
     *                                   commerce. Ignoré si [$marchand] est
     *                                   fourni : c'est sa fiche qui encaisse.
     * @param  string|null   $beneficiaireUserId Compte Tonji du bénéficiaire,
     *                                   quand il en a un.
     * @param  object|null   $marchand   Fiche marchande (id, nom, numero_tel,
     *                                   type_paynala) pour un paiement commerce.
     * @param  bool          $cloturer   Clôture la collecte après un succès.
     * @param  array<string, mixed> $trace Champs fusionnés dans `payout.request`.
     *
     * @return array{
     *   ok: bool, code: string, message: ?string, montant: int,
     *   payout_id: ?string, trans_id: ?string, numero: ?string
     * }
     *   `code` est stable et destiné aux appelants : 'succes', 'verrou',
     *   'operations_bloquees', 'solde_insuffisant', 'reservation', 'refus',
     *   'issue_inconnue'.
     */
    public function executer(
        TondoCagnotte $cagnotte,
        int $montant,
        string $canal,
        ?string $numeroE164 = null,
        ?string $beneficiaireUserId = null,
        ?object $marchand = null,
        bool $cloturer = false,
        array $trace = [],
    ): array {
        $echec = fn (string $code, string $message, array $extra = []): array => array_merge([
            'ok' => false, 'code' => $code, 'message' => $message, 'montant' => $montant,
            'payout_id' => null, 'trans_id' => null, 'numero' => $numeroE164,
        ], $extra);

        if ($montant <= 0) {
            return $echec('solde_insuffisant', 'Montant invalide.');
        }

        // Un commerce encaisse sur le numéro de SA fiche, jamais sur un numéro
        // saisi : c'est une donnée administrée, et c'est ce qui fait qu'un
        // paiement marchand ne peut pas être détourné vers un tiers.
        if ($marchand !== null) {
            $numeroE164 = $marchand->numero_tel;
            // Le bénéficiaire est un commerce, pas un compte Tonji : même si le
            // numéro correspond à un utilisateur, la ligne ne lui appartient pas.
            $beneficiaireUserId = null;
        }

        if (empty($numeroE164)) {
            return $echec('solde_insuffisant', 'Aucun numéro bénéficiaire — transfert impossible.');
        }

        // ── 1. Verrou des sorties ────────────────────────────────────────────
        $action = $marchand !== null ? 'marchand' : 'transfert';
        if ($refus = $this->verrou->refus($cagnotte->id, $cagnotte->project_id, $action)) {
            return $echec('verrou', $refus['message'], ['code_verrou' => $refus['code']]);
        }

        // ── 2. Environnement de test ─────────────────────────────────────────
        // Refuser AVANT de réserver : plus bas, une issue inconnue laisse le
        // solde amputé, il ne faut pas en arriver là pour une sortie désactivée.
        if (PaynalaPaymentService::operationsReellesBloquees()) {
            return $echec(
                'operations_bloquees',
                'Les transferts sont désactivés sur l\'environnement de test.',
            );
        }

        // Numéro local 0XXXXXXXX attendu par l'API Airtel.
        $msisdnLocal = str_starts_with($numeroE164, '+241')
            ? '0' . substr($numeroE164, 4)
            : ltrim($numeroE164, '+');

        $reference = 'TONJIDISBURSEMENT' . now()->getTimestampMs();
        $payoutId  = (string) Str::uuid();

        // Un paiement marchand se reconnaît à sa référence, comme les retraits
        // en espèces ou le transfert automatique.
        $transId = Registre::nouvelleReference($marchand ? 'payout_marchand' : 'payout_manuel');

        // Clé d'idempotence = la référence de la transaction elle-même. Le
        // COUNT(*) + 1 d'avant se répétait d'un environnement à l'autre, et la
        // recette parle au même Paynala que la production.
        $idempotencyKey = $transId;

        // ── 3. Répartition des frais ─────────────────────────────────────────
        //
        // Calculée AVANT la réservation, parce qu'elle décide du montant
        // réellement envoyé au bénéficiaire. La collecte est débitée de ce que
        // le client a saisi ; le bénéficiaire reçoit ce qui reste une fois les
        // parts retenues.
        //
        // Un taux négocié avec le marchand prime sur la somme des lignes : les
        // parts sont alors mises à l'échelle, les mêmes comptes vivant d'un
        // prélèvement plus faible. Sans fiche marchande, pas de taux imposé.
        $tauxImpose = ($marchand !== null && $marchand->frais_taux !== null)
            ? (float) $marchand->frais_taux
            : null;

        try {
            $split = $this->repartition->pour(
                $action === 'marchand' ? 'marchand' : 'transfert',
                $cagnotte->project_id,
                $montant,
                $tauxImpose,
            );
        } catch (\Throwable $e) {
            // Une répartition incohérente ne doit pas réserver de fonds : on
            // refuse avant d'avoir touché au solde.
            Log::critical('[sortie] répartition des frais invalide — sortie refusée', [
                'cagnotte' => $cagnotte->reference,
                'montant'  => $montant,
                'erreur'   => $e->getMessage(),
            ]);

            return $echec('repartition', 'Configuration des frais invalide — transfert impossible. '
                . 'Les administrateurs ont été alertés.');
        }

        $montantEnvoye = $split['net'];

        // ── 4. Réservation sous row-lock ─────────────────────────────────────
        try {
            DB::transaction(function () use (
                $cagnotte, $montant, $payoutId, $transId, $idempotencyKey,
                $reference, $numeroE164, $beneficiaireUserId, $marchand, $canal, $trace
            ) {
                $solde = (int) DB::table(project_table('cagnottes'))
                    ->where('id', $cagnotte->id)
                    ->lockForUpdate()
                    ->value('montant_collecte');

                if ($solde < $montant) {
                    throw new SoldeInsuffisant(
                        'Solde insuffisant. Disponible : '
                        . number_format($solde, 0, ',', ' ') . ' FCFA.'
                    );
                }

                DB::table(project_table('payout'))->insert([
                    'id'            => $payoutId,
                    'project_id'    => $cagnotte->project_id,
                    'cagnotte_id'   => $cagnotte->id,
                    'user_id'       => $beneficiaireUserId,   // bénéficiaire, pas le gérant
                    'trans_id'      => $transId,
                    'operateur_id'  => null,
                    'numero_tel'    => $numeroE164,
                    'montant'       => $montant,
                    'statut'        => 'initie',
                    // ⚠️ La colonne `canal` n'est PAS le canal d'origine : elle
                    // dit le RAIL du décaissement — 'mobile_money' ou
                    // 'especes' — et une contrainte CHECK n'accepte que ces
                    // deux valeurs. Y écrire « app » ou « whatsapp » ferait
                    // échouer chaque transfert. On la laisse donc à son défaut
                    // ('mobile_money') et le canal d'origine part dans
                    // `request.canal`, où il était déjà.
                    //
                    // Les deux colonnes suivantes disent la même chose et une
                    // contrainte l'exige : elles se posent ensemble.
                    'type_beneficiaire' => $marchand ? 'marchand' : 'particulier',
                    'marchand_id'       => $marchand?->id,
                    // `montant` reste le montant DÉBITÉ de la collecte, pour
                    // que la réconciliation retombe juste : solde attendu =
                    // payins − payouts. Ce qui est réellement parti au
                    // bénéficiaire est tracé à côté, avec les parts retenues.
                    'request'       => json_encode(array_filter(array_merge([
                        'idempotency_key'     => $idempotencyKey,
                        'reference'           => $reference,
                        'cagnotte_reference'  => $cagnotte->reference,
                        'numero_beneficiaire' => $numeroE164,
                        'montant'             => $montant,
                        'montant_net'         => $montantEnvoye,
                        'frais_total'         => $split['frais_total'],
                        'canal'               => $canal,
                        'marchand'            => $marchand?->nom,
                    ], $trace))),
                    'date_creation' => now(),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                DB::table(project_table('cagnottes'))
                    ->where('id', $cagnotte->id)
                    ->update([
                        'montant_collecte' => DB::raw('montant_collecte - ' . $montant),
                        'updated_at'       => now(),
                    ]);
            });
        } catch (SoldeInsuffisant $e) {
            return $echec('solde_insuffisant', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('[sortie] échec de la réservation', [
                'cagnotte' => $cagnotte->reference,
                'canal'    => $canal,
                'erreur'   => $e->getMessage(),
            ]);

            return $echec('reservation', 'Erreur lors de la réservation des fonds.');
        }

        // ── 5. Commissions d'abord ───────────────────────────────────────────
        //
        // Paynala n'accepte qu'un `msisdn` par appel : il faut enchaîner. Ordre
        // voulu par Daniel — de la plus petite part à la plus grande, donc les
        // commissions avant le bénéficiaire.
        //
        // `fraisSortis` est ce qui a réellement quitté la caisse. Si le
        // décaissement principal échoue plus bas, c'est exactement ce qu'il ne
        // faudra PAS restaurer : recréditer la collecte de commissions déjà
        // décaissées les ferait dépenser une seconde fois.
        $fraisSortis = 0;

        if ($split['parts'] !== []) {
            $fraisSortis = $this->reglement->enregistrerEtRegler(
                $split['parts'],
                $cagnotte->project_id,
                $payoutId,
                $cagnotte->id,
            )['sorti'];
        }

        // ── 6. Décaissement Paynala (hors transaction DB) ────────────────────
        // Pour un marchand, le type vient de sa fiche : c'est une donnée
        // administrée, plus fiable qu'une déduction à partir du KYC.
        $disburseType = $marchand
            ? PaynalaPaymentService::modeDisburse($marchand->type_paynala)
            : $this->paynala->resolveDisburseType(
                msisdnLocal: $msisdnLocal,
                msisdnE164:  $numeroE164,
                userId:      $beneficiaireUserId,
            );

        try {
            $disburseData = $this->paynala->disburse(
                idempotencyKey: $idempotencyKey,
                // Le NET : les parts de frais sont retenues sur le montant et
                // partent vers leurs propres comptes, pas vers le bénéficiaire.
                amount:         $montantEnvoye,
                msisdn:         $msisdnLocal,
                reference:      $reference,
                type:           $disburseType,
            );
        } catch (DecaissementRefuse $e) {
            // REFUS EXPLICITE — l'opérateur a répondu non : le NET n'est pas
            // parti. La réservation est compensée dans la même transaction que
            // le passage en `echec`, pour que le solde ne reste jamais amputé
            // d'un montant qui n'a pas quitté la collecte.
            //
            // Ce `catch` vient AVANT celui des autres erreurs : c'est la seule
            // exception qui autorise à recréditer, elle doit donc être
            // reconnue en premier.
            //
            // ⚠️ RESTAURATION PARTIELLE. Les commissions sont parties AVANT ce
            // décaissement et ne se rattrapent pas — l'opérateur ne rembourse
            // pas. On ne rend donc que ce qui n'a pas quitté la caisse.
            // Restaurer la totalité recréditerait la collecte de commissions
            // déjà décaissées, et la sortie suivante les dépenserait une
            // seconde fois. C'est le prix de l'ordre « commissions d'abord ».
            $aRestaurer = $montant - $fraisSortis;

            DB::transaction(function () use ($payoutId, $cagnotte, $montant, $fraisSortis, $aRestaurer, $e) {
                DB::table(project_table('payout'))->where('id', $payoutId)->update([
                    'statut'     => 'echec',
                    'response'   => json_encode([
                        'error'          => $e->getMessage(),
                        'solde_restaure' => $aRestaurer > 0,
                        'montant_debite' => $montant,
                        'frais_sortis'   => $fraisSortis,
                        'montant_rendu'  => $aRestaurer,
                    ]),
                    'updated_at' => now(),
                ]);

                if ($aRestaurer > 0) {
                    DB::table(project_table('cagnottes'))
                        ->where('id', $cagnotte->id)
                        ->update([
                            'montant_collecte' => DB::raw('montant_collecte + ' . $aRestaurer),
                            'updated_at'       => now(),
                        ]);
                }
            });

            // CRITICAL dès qu'une commission est partie : le client a perdu de
            // l'argent sur une opération qui a échoué, et quelqu'un doit le
            // savoir sans attendre la réconciliation.
            Log::log(
                $fraisSortis > 0 ? 'critical' : 'warning',
                '[sortie] décaissement refusé — solde restauré' . ($fraisSortis > 0 ? ' PARTIELLEMENT' : ''),
                [
                    'cagnotte'      => $cagnotte->reference,
                    'canal'         => $canal,
                    'payout_id'     => $payoutId,
                    'trans_id'      => $transId,
                    'montant'       => $montant,
                    'frais_sortis'  => $fraisSortis,
                    'montant_rendu' => $aRestaurer,
                    'erreur'        => $e->getMessage(),
                ],
            );

            $this->alerterAdmins($cagnotte, $montant, $numeroE164, $transId, $canal, $e->getMessage(), true);

            return [
                'ok' => false, 'code' => 'refus', 'montant' => $montant,
                'payout_id' => $payoutId, 'trans_id' => $transId, 'numero' => $numeroE164,
                // Le message dit la vérité, y compris quand elle est
                // désagréable : promettre un retour intégral alors que des
                // frais sont partis ferait constater l'écart au solde suivant,
                // sans explication.
                'message' => $fraisSortis > 0
                    ? 'Le transfert a été refusé par l\'opérateur. '
                        . number_format($aRestaurer, 0, ',', ' ') . ' FCFA sont revenus dans la collecte ; '
                        . number_format($fraisSortis, 0, ',', ' ') . ' FCFA de frais avaient déjà été prélevés. '
                        . 'Les administrateurs ont été alertés.'
                    : 'Le transfert a été refusé par l\'opérateur. '
                        . 'Le montant est revenu dans la collecte.',
            ];
        } catch (ConnectionException | \RuntimeException $e) {
            // ISSUE INCONNUE — tout ce qui n'est pas un refus affirmé : coupure
            // réseau, 5xx de passerelle, réponse illisible. La demande a pu
            // être reçue et traitée côté opérateur.
            //
            // **Le solde n'est PAS restauré.** Recréditer ici ferait croire à
            // la collecte qu'elle détient un montant déjà sorti, et la sortie
            // suivante le dépenserait une seconde fois — perte irrécupérable
            // que rien ne signale. Le payout reste `en_cours`, ce qui le fait
            // remonter dans les réconciliations à traiter, et le cas se règle
            // avec l'opérateur.
            DB::table(project_table('payout'))->where('id', $payoutId)->update([
                'statut'     => 'en_cours',
                'response'   => json_encode(['error' => $e->getMessage(), 'issue' => 'inconnue']),
                'updated_at' => now(),
            ]);

            Log::critical('[sortie] issue inconnue — solde non restauré, régularisation requise', [
                'cagnotte'        => $cagnotte->reference,
                'canal'           => $canal,
                'payout_id'       => $payoutId,
                'trans_id'        => $transId,
                'idempotency_key' => $idempotencyKey,
                'montant'         => $montant,
                'erreur'          => $e->getMessage(),
            ]);

            $this->alerterAdmins($cagnotte, $montant, $numeroE164, $transId, $canal, $e->getMessage(), false);

            return [
                'ok' => false, 'code' => 'issue_inconnue', 'montant' => $montant,
                'payout_id' => $payoutId, 'trans_id' => $transId, 'numero' => $numeroE164,
                'message' => 'L\'issue du transfert est inconnue : elle est en cours de '
                    . 'vérification auprès de l\'opérateur. Les administrateurs ont été '
                    . 'alertés — ne relancez pas le transfert.',
            ];
        }

        // ── 5. Confirmation ──────────────────────────────────────────────────
        DB::transaction(function () use ($payoutId, $disburseData, $cagnotte, $cloturer) {
            DB::table(project_table('payout'))->where('id', $payoutId)->update([
                'statut'       => 'succes',
                'operateur_id' => $disburseData['airtel_money_id'] ?? null,
                'response'     => json_encode($disburseData),
                'updated_at'   => now(),
            ]);

            if ($cloturer) {
                DB::table(project_table('cagnottes'))
                    ->where('id', $cagnotte->id)
                    ->update(['statut' => 'cloturee', 'updated_at' => now()]);
            }
        });

        // Paiement marchand : prévenir l'enseigne. La prise est atomique et
        // sans effet si un autre chemin a déjà notifié — tout canal peut donc
        // appeler ceci sans se coordonner. Un échec ici ne doit pas faire
        // croire à un transfert raté : l'argent est parti, la réponse le dit.
        if ($marchand !== null) {
            try {
                $this->notifier->signaler($payoutId);
            } catch (\Throwable $e) {
                Log::error('[sortie] notification marchand non déclenchée', [
                    'payout_id' => $payoutId,
                    'erreur'    => $e->getMessage(),
                ]);
            }
        }

        return [
            'ok' => true, 'code' => 'succes', 'message' => null,
            // `montant` = débité de la collecte ; `montant_net` = reçu par le
            // bénéficiaire. Les appelants qui annoncent un montant à l'écran
            // doivent dire le net, sinon ils promettent ce qui n'arrive pas.
            'montant' => $montant,
            'montant_net' => $montantEnvoye,
            'frais_total' => $split['frais_total'],
            'payout_id' => $payoutId, 'trans_id' => $transId, 'numero' => $numeroE164,
        ];
    }

    /**
     * Alerte les administrateurs abonnés aux « problèmes techniques ».
     *
     * Posée ici et non chez l'appelant : le bot WhatsApp se contentait d'un
     * log, que personne ne lit. Un transfert bloqué doit sortir du serveur quel
     * que soit le canal qui l'a lancé.
     *
     * @param bool $soldeRestaure Dit à l'administrateur s'il doit régulariser
     *                            un solde ou seulement constater un refus.
     */
    private function alerterAdmins(
        TondoCagnotte $cagnotte,
        int $montant,
        string $numeroE164,
        string $transId,
        string $canal,
        string $erreur,
        bool $soldeRestaure,
    ): void {
        try {
            $ref        = e($cagnotte->reference);
            $montantFmt = number_format($montant, 0, ',', ' ') . ' FCFA';
            $benef      = e($numeroE164);
            $err        = e($erreur);
            $suite      = $soldeRestaure
                ? '<p>Le montant a été <strong>remis dans la collecte</strong> : aucune régularisation de solde n\'est nécessaire, mais la cause du refus mérite un coup d\'œil.</p>'
                : '<p><strong>Le solde n\'a pas été restauré</strong> et l\'issue du décaissement est inconnue. Vérifiez chez l\'opérateur <em>avant</em> toute nouvelle tentative.</p>';

            $corps = <<<HTML
            <p>Un <strong>transfert a échoué</strong> et n'a pas pu être envoyé au bénéficiaire.</p>
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#FFF0EE;border:1px solid #F3C9C3;border-radius:12px;margin:8px 0;">
              <tr><td style="padding:16px;font-size:14px;line-height:1.7;">
                <strong>Collecte :</strong> {$ref}<br>
                <strong>Montant :</strong> {$montantFmt}<br>
                <strong>Bénéficiaire :</strong> {$benef}<br>
                <strong>Transaction :</strong> {$transId}<br>
                <strong>Canal :</strong> {$canal}<br>
                <strong>Erreur :</strong> {$err}
              </td></tr>
            </table>
            {$suite}
            HTML;

            app(\App\Services\Mail\AdminNotifier::class)->notifier(
                $cagnotte->project_id,
                'problemes',
                'Échec de transfert — action requise',
                'Échec de transfert',
                $corps,
                'Ouvrir la réconciliation',
                rtrim((string) config('services.admin_dashboard_url'), '/') . '/reconciliation',
            );
        } catch (\Throwable $e) {
            Log::error('[sortie] impossible d\'envoyer l\'alerte admin', [
                'mail_error' => $e->getMessage(),
            ]);
        }
    }
}
