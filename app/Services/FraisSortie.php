<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ce qu'un reversement devrait coûter — **le seul endroit où la règle vit**.
 *
 * Deux chemins font sortir de l'argent d'une collecte et ils ne partagent pas
 * leur code : {@see SortieArgent} pour l'app, le web et le bot,
 * {@see ReversementService} pour le reversement automatique de 18 h et la
 * suppression de compte. Une règle écrite dans l'un ne s'appliquerait pas à
 * l'autre — et comme le cron est celui qui sort le plus d'argent en volume, le
 * trou serait du mauvais côté. Les deux appellent donc ce service.
 *
 * ── La règle (Daniel, 2026-10-09) ───────────────────────────────────────────
 *
 * La gratuité ne se juge pas sur le montant du virement — sinon il suffit de le
 * découper — mais sur ce que la collecte a REÇU :
 *
 *   1. une cagnotte dont le cumul des cotisations dépasse la franchise n'y a
 *      plus droit, quel que soit le montant sorti ;
 *   2. et si l'organisateur a déjà consommé une gratuité dans le mois alors que
 *      ses collectes cumulées dépassent la franchise, aucune autre de ses
 *      cagnottes n'y a droit non plus.
 *
 * Les deux ensemble bornent la gratuité à **une franchise par organisateur et
 * par mois**, quel que soit le nombre de cagnottes ou de virements.
 *
 * Reste ouvert, et assumé : le compte est la clé. Plusieurs comptes valent
 * autant de franchises. Compter aussi sur le numéro de retrait fermerait ce
 * reste — il est immuable après création, donc difficile à multiplier.
 *
 * ── Ce que ce service ne fait PAS ───────────────────────────────────────────
 *
 * Il ne diminue pas le montant envoyé. Tonji ne prélève rien : Paynala traite
 * la transaction et prélève selon sa propre grille. Le chiffre rendu ici est
 * enregistré sur le payout (`frais_attendus`) pour la réconciliation et pour
 * la règle 2 — c'est lui qui dit si une gratuité a été consommée.
 */
class FraisSortie
{
    public function __construct(private readonly TondoConfigService $config) {}

    /**
     * Frais attendus pour sortir [$montant] de [$cagnotte].
     *
     * @return array{frais:int, gratuit:bool, motif:string}
     */
    public function pour(object $cagnotte, int $montant): array
    {
        $cfg = $this->config->getOperatorConfig((string) $cagnotte->project_id);

        $franchise = (int) ($cfg['franchise_retrait'] ?? 0);
        $plafond   = (int) ($cfg['plafond_frais_retrait'] ?? 0);
        $taux      = $this->taux($cagnotte, $cfg);

        // Aucun taux réglé : il n'y a rien à prélever, et la question de la
        // franchise ne se pose pas.
        if ($taux <= 0) {
            return ['frais' => 0, 'gratuit' => true, 'motif' => 'taux_nul'];
        }

        if ($franchise > 0 && $this->eligible($cagnotte, $franchise)) {
            return ['frais' => 0, 'gratuit' => true, 'motif' => 'franchise'];
        }

        $frais = (int) round($montant * $taux);
        if ($plafond > 0 && $frais > $plafond) {
            $frais = $plafond;
        }

        return ['frais' => $frais, 'gratuit' => false, 'motif' => 'hors_franchise'];
    }

    /** Taux applicable, selon le type de collecte et le profil du gérant. */
    private function taux(object $cagnotte, array $cfg): float
    {
        $estTontine = ($cagnotte->type ?? '') === 'tontine_periodique';

        $typeCompte = DB::table('users')
            ->where('id', $cagnotte->user_id)
            ->value('type_compte');

        $parType = $cfg['frais_retrait'][$estTontine ? 'tontine' : 'cagnotte'] ?? [];

        return (float) ($parType[$typeCompte === 'association' ? 'association' : 'particulier'] ?? 0);
    }

    /** La collecte a-t-elle encore droit à la gratuité ? */
    private function eligible(object $cagnotte, int $franchise): bool
    {
        // RÈGLE 1 — sur le CUMUL des cotisations, jamais sur le solde : celui-ci
        // baisse à chaque sortie, et une cagnotte repasserait sous le seuil en
        // se vidant.
        if ((int) ($cagnotte->cumul_cotisations ?? 0) > $franchise) {
            return false;
        }

        // RÈGLE 2 — l'organisateur n'a droit qu'à une franchise par mois, toutes
        // ses collectes confondues. Tant qu'il reste sous la franchise sur le
        // mois, plusieurs petites cagnottes peuvent être gratuites : leur total
        // ne dépassera pas la franchise, c'est l'invariant recherché.
        $debutMois = Carbon::now('Africa/Libreville')->startOfMonth();

        $siennes = DB::table(project_table('cagnottes'))
            ->where('user_id', $cagnotte->user_id)
            ->pluck('id');

        $collecteDuMois = (int) DB::table(project_table('paiements'))
            ->whereIn('cagnotte_id', $siennes)
            ->where('date', '>=', $debutMois)
            // `actif` écarte les doublons neutralisés par le correctif du
            // 2026-08-31 : les compter rapprocherait du seuil sans argent réel.
            ->where(fn ($q) => $q->where('actif', true)->orWhereNull('actif'))
            ->sum('montant');

        if ($collecteDuMois <= $franchise) {
            return true;
        }

        // Au-delà, tout dépend d'une gratuité déjà consommée ce mois-ci. C'est
        // `frais_attendus = 0` sur un payout abouti qui en fait foi — la trace
        // est posée par les deux chemins de sortie.
        $dejaConsommee = DB::table(project_table('payout'))
            ->whereIn('cagnotte_id', $siennes)
            ->where('date_creation', '>=', $debutMois)
            ->whereIn('statut', ['initie', 'en_cours', 'succes'])
            ->where('frais_attendus', 0)
            ->exists();

        return ! $dejaConsommee;
    }
}
