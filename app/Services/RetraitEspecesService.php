<?php

namespace App\Services;

use App\Exceptions\RetraitImpossible;
use App\Models\TondoAgent;
use App\Models\TondoCagnotte;
use App\Support\RetraitEspeces;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Retrait en espèces chez un agent : demande, validation, annulation.
 *
 * Une règle domine tout le reste : l'agent ne remet des billets que sur un
 * dossier `valide`. Et un dossier ne devient `valide` que dans une transaction
 * qui, d'un seul tenant, vérifie le code, le plafond journalier et le solde,
 * débite la cagnotte et écrit la sortie dans le grand livre (payout). Aucun
 * appel à l'agrégateur de paiement : ce sont les billets de l'agent, Tonji le
 * rembourse par ailleurs. La validation est donc rapide et entièrement sous
 * notre contrôle.
 */
class RetraitEspecesService
{
    /** Types et statuts de cagnotte admis — les mêmes que pour un transfert. */
    private const TYPE_ADMIS    = 'cagnotte_ouverte';
    private const STATUTS_ADMIS = ['active', 'en_cours'];

    public function __construct(
        private SmsRetraitService $sms,
        private VerificationNumeroRetrait $verification,
    ) {}

    // ── Demande ─────────────────────────────────────────────────────────────

    /**
     * Crée la demande et envoie le code au numéro de retrait de la cagnotte.
     *
     * @return array{retrait: object, titulaire: string, rejoue: bool}
     * @throws RetraitImpossible
     */
    public function demander(TondoAgent $agent, string $cagnotteReference, int $montant, string $cle): array
    {
        $table = project_table('retraits_especes');

        // 1. Idempotence. Rejouer la même clé renvoie le même dossier — sauf si
        //    elle désigne une autre opération, ce qui trahit un bug du terminal.
        $existant = DB::table($table)->where('agent_id', $agent->id)->where('cle_idempotence', $cle)->first();
        if ($existant) {
            return $this->rejouerDemande($existant, $cagnotteReference, $montant);
        }

        // 2. Montant.
        if ($montant < 1) {
            throw new RetraitImpossible('Montant invalide.', 422, 'montant_invalide');
        }
        if ($montant > $agent->plafond_operation_fcfa) {
            throw new RetraitImpossible(
                'Montant supérieur au plafond par opération de cet agent ('
                . RetraitEspeces::formaterMontant($agent->plafond_operation_fcfa) . ' FCFA).',
                422, 'plafond_operation',
            );
        }

        // 3. Cagnotte.
        $cagnotte = TondoCagnotte::where('project_id', $agent->project_id)
            ->where('reference', $cagnotteReference)->first();
        if (! $cagnotte) {
            throw new RetraitImpossible('Cagnotte introuvable.', 404, 'cagnotte_introuvable');
        }
        // Les tontines sont exclues : plusieurs traitements comptent les cycles
        // d'une tontine en comptant ses payouts réussis, et un retrait en
        // espèces y passerait pour un cycle terminé.
        if ($cagnotte->type !== self::TYPE_ADMIS) {
            throw new RetraitImpossible('Le retrait en espèces est réservé aux cagnottes ouvertes.', 422, 'type_cagnotte');
        }
        if (! in_array($cagnotte->statut, self::STATUTS_ADMIS, true)) {
            throw new RetraitImpossible('Cette cagnotte est clôturée.', 422, 'cagnotte_cloturee');
        }
        if (! $cagnotte->numero_retrait) {
            throw new RetraitImpossible('Cette cagnotte n\'a pas de numéro de retrait.', 422, 'numero_retrait_absent');
        }

        // 4. Limite de demandes, décomptée AVANT les contrôles de solde : sans
        //    elle, un agent pourrait retrouver le solde d'une cagnotte par essais
        //    successifs, ou inonder de SMS le téléphone du titulaire.
        $cleLimite = "retrait-demande:{$agent->id}:{$cagnotte->id}";
        if (RateLimiter::tooManyAttempts($cleLimite, (int) config('retrait.demandes_par_heure'))) {
            throw new RetraitImpossible('Trop de demandes sur cette cagnotte. Réessayez plus tard.', 429, 'trop_de_demandes');
        }
        RateLimiter::hit($cleLimite, 3600);

        // 5. Solde et plafond journalier — contrôles indicatifs, pour ne pas
        //    envoyer un code voué à l'échec. Ils seront refaits sous verrou à la
        //    validation, seule vérification qui fait foi.
        if ($cagnotte->montant_collecte < $montant) {
            throw new RetraitImpossible('Solde de la cagnotte insuffisant.', 422, 'solde_insuffisant');
        }
        if ($this->montantRetireAujourdhui($agent->id) + $montant > $agent->plafond_journalier_fcfa) {
            throw new RetraitImpossible('Plafond journalier de l\'agent atteint.', 422, 'plafond_journalier');
        }

        // 6. Titulaire. Son nom KYC est ce que l'agent compare à la pièce
        //    d'identité : sans lui, la vérification au comptoir ne vaut rien,
        //    et des espèces remises à tort ne se récupèrent pas.
        $titulaire = $this->titulaire($cagnotte);
        if ($titulaire === null) {
            throw new RetraitImpossible(
                'Identité du titulaire invérifiable pour l\'instant. Réessayez dans quelques minutes.',
                503, 'kyc_indisponible',
            );
        }

        // 7. Dossier.
        $code = RetraitEspeces::genererCode((int) config('retrait.code_longueur'));

        try {
            $retrait = DB::transaction(function () use ($agent, $cagnotte, $montant, $cle, $code, $table) {
                $this->expirerEchues(cagnotteId: $cagnotte->id);

                // Une nouvelle demande remplace celle en attente : le titulaire ne
                // doit avoir qu'un code valable à la fois, pour un seul montant.
                DB::table($table)
                    ->where('cagnotte_id', $cagnotte->id)
                    ->where('statut', 'en_attente_code')
                    ->update([
                        'statut'      => 'annule',
                        'motif_refus' => 'Remplacée par une nouvelle demande.',
                        'termine_at'  => now(),
                        'updated_at'  => now(),
                    ]);

                $id = (string) Str::uuid();
                DB::table($table)->insert([
                    'id'              => $id,
                    'project_id'      => $agent->project_id,
                    'reference'       => RetraitEspeces::genererReference(),
                    'agent_id'        => $agent->id,
                    'cagnotte_id'     => $cagnotte->id,
                    'montant_fcfa'    => $montant,
                    'statut'          => 'en_attente_code',
                    'code_hash'       => Hash::make($code),
                    'code_expire_at'  => now()->addMinutes((int) config('retrait.code_validite_min')),
                    'cle_idempotence' => $cle,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                return DB::table($table)->where('id', $id)->first();
            });
        } catch (QueryException $e) {
            // Même clé envoyée deux fois au même instant : la seconde retrouve le
            // dossier créé par la première.
            $concurrent = DB::table($table)->where('agent_id', $agent->id)->where('cle_idempotence', $cle)->first();
            if ($concurrent) {
                return $this->rejouerDemande($concurrent, $cagnotteReference, $montant);
            }
            // Un autre terminal a ouvert une demande sur la même cagnotte au même
            // instant : l'index unique des demandes en attente a tranché.
            throw new RetraitImpossible('Une demande est déjà en cours sur cette cagnotte.', 409, 'demande_en_cours');
        }

        // 8. Code au titulaire. S'il ne part pas, le dossier est annulé : un
        //    code que personne n'a reçu ne doit pas rester valable.
        try {
            $this->sms->envoyer($cagnotte->numero_retrait, RetraitEspeces::texteSmsDemande(
                $montant, $cagnotte->reference, $agent->nom, $agent->identifiant, $code,
                (int) config('retrait.code_validite_min'),
            ), 'demande');
        } catch (\Throwable $e) {
            DB::table($table)->where('id', $retrait->id)->where('statut', 'en_attente_code')->update([
                'statut' => 'annule', 'motif_refus' => 'SMS non délivré.', 'termine_at' => now(), 'updated_at' => now(),
            ]);
            Log::error('[retrait] SMS de demande non délivré', ['reference' => $retrait->reference, 'erreur' => $e->getMessage()]);
            throw new RetraitImpossible('Le code n\'a pas pu être envoyé au titulaire. Réessayez.', 502, 'sms_echec');
        }

        return ['retrait' => $retrait, 'titulaire' => $titulaire, 'rejoue' => false];
    }

    // ── Validation ──────────────────────────────────────────────────────────

    /**
     * Vérifie le code et, s'il est juste, fait sortir l'argent.
     *
     * Rejouable sans risque : sur un dossier déjà validé, renvoie la validation
     * d'origine sans rien débiter une seconde fois.
     *
     * @return object  Le dossier, dans son état après l'opération.
     * @throws RetraitImpossible
     */
    public function valider(TondoAgent $agent, string $reference, string $code): object
    {
        $table   = project_table('retraits_especes');
        $retrait = $this->dossierDeLAgent($agent, $reference);

        $issue = DB::transaction(function () use ($agent, $retrait, $code, $table) {
            // Verrou sur le dossier : deux validations simultanées du même code
            // s'attendent, et la seconde trouve un dossier déjà validé.
            $r = DB::table($table)->where('id', $retrait->id)->lockForUpdate()->first();

            if ($r->statut !== 'en_attente_code') {
                return ['dossier' => $r, 'valide_maintenant' => false];
            }

            if (Carbon::parse($r->code_expire_at)->isPast()) {
                return ['dossier' => $this->clore($r->id, 'expire', 'Code non saisi à temps.'), 'valide_maintenant' => false];
            }

            if (! Hash::check($code, $r->code_hash)) {
                $tentatives = $r->code_tentatives + 1;
                $maximum    = (int) config('retrait.code_tentatives');
                DB::table($table)->where('id', $r->id)->update(['code_tentatives' => $tentatives, 'updated_at' => now()]);

                $dossier = $tentatives >= $maximum
                    ? $this->clore($r->id, 'refuse', 'Trop de codes erronés.')
                    : DB::table($table)->where('id', $r->id)->first();

                return ['dossier' => $dossier, 'valide_maintenant' => false];
            }

            // Verrou sur l'agent : deux validations en parallèle sur deux
            // cagnottes ne peuvent pas franchir ensemble le plafond journalier.
            $a = TondoAgent::whereKey($agent->id)->lockForUpdate()->first();
            if ($this->montantRetireAujourdhui($a->id) + $r->montant_fcfa > $a->plafond_journalier_fcfa) {
                return ['dossier' => $this->clore($r->id, 'refuse', 'Plafond journalier de l\'agent atteint.'), 'valide_maintenant' => false];
            }

            // Débit conditionnel en une seule instruction : il n'a lieu que si le
            // solde suffit et que la cagnotte est toujours éligible. Aucune
            // lecture-puis-écriture qu'un autre débit pourrait devancer.
            $cagnotte = DB::table(project_table('cagnottes'))->where('id', $r->cagnotte_id)->first();
            $debite = DB::table(project_table('cagnottes'))
                ->where('id', $r->cagnotte_id)
                ->where('type', self::TYPE_ADMIS)
                ->whereIn('statut', self::STATUTS_ADMIS)
                ->where('montant_collecte', '>=', $r->montant_fcfa)
                ->update([
                    'montant_collecte' => DB::raw('montant_collecte - ' . (int) $r->montant_fcfa),
                    'updated_at'       => now(),
                ]);
            if ($debite === 0) {
                return ['dossier' => $this->clore($r->id, 'refuse', 'Solde insuffisant ou cagnotte indisponible.'), 'valide_maintenant' => false];
            }

            // La sortie entre au grand livre comme n'importe quel transfert : la
            // réconciliation et l'historique du gérant la comptent d'eux-mêmes.
            $a->loadMissing(['partenaire', 'support']);
            $payoutId = (string) Str::uuid();
            DB::table(project_table('payout'))->insert([
                'id'            => $payoutId,
                'project_id'    => $r->project_id,
                'cagnotte_id'   => $r->cagnotte_id,
                'user_id'       => DB::table('users')->where('project_id', $r->project_id)
                    ->where('numero', $cagnotte->numero_retrait)->value('id'),
                'trans_id'      => $r->reference,
                'operateur_id'  => $a->partenaire?->sigle,
                'numero_tel'    => $cagnotte->numero_retrait,
                'montant'       => $r->montant_fcfa,
                'statut'        => 'succes',
                'canal'         => 'especes',
                'agent_id'      => $a->id,
                'request'       => json_encode([
                    'retrait_id' => $r->id,
                    'agent'      => $a->identifiant,
                    'partenaire' => $a->partenaire?->sigle,
                    'support'    => $a->support?->sigle,
                ]),
                'date_creation' => now(),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            DB::table($table)->where('id', $r->id)->update([
                'statut'     => 'valide',
                'payout_id'  => $payoutId,
                'termine_at' => now(),
                'updated_at' => now(),
            ]);

            return ['dossier' => DB::table($table)->where('id', $r->id)->first(), 'valide_maintenant' => true];
        });

        if ($issue['valide_maintenant']) {
            $this->confirmerAuTitulaire($agent, $issue['dossier']);
        }

        return $issue['dossier'];
    }

    // ── Annulation, lecture ─────────────────────────────────────────────────

    /** Annule une demande en attente. Sans effet sur un dossier déjà terminé. */
    public function annuler(TondoAgent $agent, string $reference): object
    {
        $retrait = $this->dossierDeLAgent($agent, $reference);

        return DB::transaction(function () use ($retrait) {
            $r = DB::table(project_table('retraits_especes'))->where('id', $retrait->id)->lockForUpdate()->first();

            return $r->statut === 'en_attente_code'
                ? $this->clore($r->id, 'annule', 'Annulée par l\'agent.')
                : $r;
        });
    }

    /** Dossier d'un agent, avec l'expiration appliquée s'il y a lieu. */
    public function dossierDeLAgent(TondoAgent $agent, string $reference): object
    {
        if (! RetraitEspeces::referenceValide($reference)) {
            throw new RetraitImpossible('Retrait introuvable.', 404, 'retrait_introuvable');
        }

        $table   = project_table('retraits_especes');
        $retrait = DB::table($table)->where('reference', $reference)->where('agent_id', $agent->id)->first();
        if (! $retrait) {
            // Un agent ne voit que ses propres dossiers : la référence d'un
            // autre terminal répond comme une référence inexistante.
            throw new RetraitImpossible('Retrait introuvable.', 404, 'retrait_introuvable');
        }

        if ($retrait->statut === 'en_attente_code' && Carbon::parse($retrait->code_expire_at)->isPast()) {
            $this->expirerEchues(retraitId: $retrait->id);
            $retrait = DB::table($table)->where('id', $retrait->id)->first();
        }

        return $retrait;
    }

    /** Montant validé par l'agent depuis minuit, heure de Libreville. */
    public function montantRetireAujourdhui(string $agentId): int
    {
        return (int) DB::table(project_table('retraits_especes'))
            ->where('agent_id', $agentId)
            ->where('statut', 'valide')
            ->where('termine_at', '>=', $this->debutJournee())
            ->sum('montant_fcfa');
    }

    /** Minuit à Libreville, exprimé en UTC pour la comparaison en base. */
    public function debutJournee(?string $jour = null): Carbon
    {
        $fuseau = (string) config('retrait.fuseau');
        $local  = $jour ? Carbon::parse($jour, $fuseau) : now($fuseau);

        return $local->startOfDay()->utc();
    }

    /**
     * Nom KYC du titulaire du numéro de retrait, ou null si invérifiable.
     *
     * Le KYC est mis en cache 24 h : un rappel à chaque consultation d'état ne
     * coûte en pratique aucun appel réseau.
     */
    public function titulaire(TondoCagnotte $cagnotte): ?string
    {
        $faits = $this->verification->verifier((string) $cagnotte->numero_retrait, $cagnotte->project_id);

        return $faits['kycOk'] === true ? $faits['titulaire'] : null;
    }

    /**
     * Passe en `expire` les demandes dont le code a expiré.
     *
     * Appliqué au moment où l'on lit ou touche un dossier, plutôt que par une
     * tâche planifiée : l'état reste juste sans dépendre d'un cron.
     */
    public function expirerEchues(?string $cagnotteId = null, ?string $retraitId = null): void
    {
        DB::table(project_table('retraits_especes'))
            ->where('statut', 'en_attente_code')
            ->where('code_expire_at', '<', now())
            ->when($cagnotteId, fn ($q) => $q->where('cagnotte_id', $cagnotteId))
            ->when($retraitId, fn ($q) => $q->where('id', $retraitId))
            ->update([
                'statut'      => 'expire',
                'motif_refus' => 'Code non saisi à temps.',
                'termine_at'  => now(),
                'updated_at'  => now(),
            ]);
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /** Rejoue une demande déjà enregistrée sous la même clé d'idempotence. */
    private function rejouerDemande(object $existant, string $cagnotteReference, int $montant): array
    {
        $cagnotte = TondoCagnotte::find($existant->cagnotte_id);

        if (! $cagnotte || $cagnotte->reference !== $cagnotteReference || (int) $existant->montant_fcfa !== $montant) {
            throw new RetraitImpossible(
                'Cette clé d\'idempotence a déjà servi pour une autre demande.',
                409, 'cle_reutilisee',
            );
        }

        return ['retrait' => $existant, 'titulaire' => $this->titulaire($cagnotte) ?? '', 'rejoue' => true];
    }

    /** Termine un dossier sur une issue sans sortie d'argent. */
    private function clore(string $id, string $statut, string $motif): object
    {
        $table = project_table('retraits_especes');
        DB::table($table)->where('id', $id)->update([
            'statut'      => $statut,
            'motif_refus' => $motif,
            'termine_at'  => now(),
            'updated_at'  => now(),
        ]);

        return DB::table($table)->where('id', $id)->first();
    }

    /**
     * SMS de confirmation au titulaire. Un échec est journalisé mais n'annule
     * rien : l'argent est sorti, et le dossier comme le grand livre en font foi.
     */
    private function confirmerAuTitulaire(TondoAgent $agent, object $dossier): void
    {
        try {
            $cagnotte = TondoCagnotte::find($dossier->cagnotte_id);
            $this->sms->envoyer($cagnotte->numero_retrait, RetraitEspeces::texteSmsConfirmation(
                (int) $dossier->montant_fcfa, $agent->nom, $agent->identifiant, $dossier->reference,
                config('retrait.numero_assistance'),
            ), 'confirmation');
        } catch (\Throwable $e) {
            Log::warning('[retrait] SMS de confirmation non délivré', [
                'reference' => $dossier->reference,
                'erreur'    => $e->getMessage(),
            ]);
        }
    }
}
