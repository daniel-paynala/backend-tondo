<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ce que le système a fait sur une période, en chiffres.
 *
 * À ne pas confondre avec {@see Sante}, qui dit si le système va bien. Celui-ci
 * dit ce qu'il a produit — combien on a collecté, transféré, payé. L'un se tait
 * quand tout va bien, l'autre parle toujours : un rapport d'activité qui
 * n'arrive qu'en cas de problème ne sert à rien, c'est justement sa régularité
 * qui rend une anomalie visible.
 *
 * Les montants sont en FCFA, entiers. Les comptages portent sur ce qui a
 * RÉUSSI : une tentative d'encaissement refusée n'est pas une cotisation, et la
 * compter gonflerait les chiffres de ce qui n'est jamais entré en caisse. Le
 * taux de réussite, lui, est rendu à part.
 */
class RapportActivite
{
    /**
     * @return array<string, mixed>
     */
    public function pour(string $projectId, Carbon $debut, Carbon $fin): array
    {
        return [
            'periode' => [
                'debut' => $debut->toIso8601String(),
                'fin'   => $fin->toIso8601String(),
            ],
            'cotisations' => $this->cotisations($projectId, $debut, $fin),
            'sorties'     => $this->sorties($projectId, $debut, $fin),
            'creations'   => $this->creations($projectId, $debut, $fin),
        ];
    }

    /**
     * Cotisations réellement encaissées, et ce qu'elles ont rapporté.
     *
     * La table `paiements` ne porte que les cotisations CONFIRMÉES : c'est elle
     * qui fait foi. `payin` sert au taux de réussite — il compte aussi les
     * tentatives abandonnées, celles où le cotisant n'a jamais validé sur son
     * téléphone.
     */
    private function cotisations(string $projectId, Carbon $debut, Carbon $fin): array
    {
        $paiements = project_table('paiements');

        $base = DB::table($paiements)
            ->where('project_id', $projectId)
            ->whereBetween('created_at', [$debut, $fin])
            ->where('actif', true);

        $nombre = (clone $base)->count();
        $montant = (int) (clone $base)->sum('montant');

        $resultat = [
            'nombre'  => $nombre,
            'montant' => $montant,
            // Le panier moyen dit souvent plus que le total : deux jours à
            // 500 000 FCFA n'ont rien à voir selon qu'ils viennent de dix
            // cotisations ou de deux cents.
            'moyenne' => $nombre > 0 ? intdiv($montant, $nombre) : 0,
        ];

        if (Schema::hasTable(project_table('payin'))) {
            $tentatives = DB::table(project_table('payin'))
                ->where('project_id', $projectId)
                ->whereBetween('date_creation', [$debut, $fin]);

            $total = (clone $tentatives)->count();
            $reussies = (clone $tentatives)->where('statut', 'succes')->count();

            $resultat['tentatives'] = $total;
            $resultat['taux_reussite'] = $total > 0
                ? round($reussies * 100 / $total, 1)
                : null;
        }

        return $resultat;
    }

    /**
     * Ce qui est sorti : transferts vers un numéro, et paiements de commerces.
     *
     * Les deux vivent dans la même table et se distinguent par `marchand_id`.
     * Les séparer compte, parce qu'ils ne disent pas la même chose : l'un est
     * un bénéficiaire qui récupère son argent, l'autre un achat.
     */
    private function sorties(string $projectId, Carbon $debut, Carbon $fin): array
    {
        $payout = project_table('payout');

        if (! Schema::hasTable($payout)) {
            return ['transferts' => null, 'marchands' => null];
        }

        $reussis = fn () => DB::table($payout)
            ->where('project_id', $projectId)
            ->whereBetween('date_creation', [$debut, $fin])
            ->where('statut', 'succes');

        $transferts = $reussis()->whereNull('marchand_id');
        $marchands  = $reussis()->whereNotNull('marchand_id');

        return [
            'transferts' => [
                'nombre'  => (clone $transferts)->count(),
                'montant' => (int) (clone $transferts)->sum('montant'),
            ],
            'marchands' => [
                'nombre'  => (clone $marchands)->count(),
                'montant' => (int) (clone $marchands)->sum('montant'),
            ],
            // Les sorties en échec ne sont pas une mesure d'activité mais un
            // signal : de l'argent que quelqu'un attend et n'a pas reçu.
            //
            // `echec` et rien d'autre : `statut` est une ÉNUMÉRATION, et
            // nommer une valeur qui n'y figure pas fait échouer la requête
            // entière (22P02) — pas seulement la condition. J'avais écrit
            // « refuse », qui n'existe pas. Les valeurs admises sont
            // initie, en_cours, succes, echec, annule ; `annule` est une
            // décision, pas une panne, et ne compte donc pas ici.
            'en_echec' => DB::table($payout)
                ->where('project_id', $projectId)
                ->whereBetween('date_creation', [$debut, $fin])
                ->where('statut', 'echec')
                ->count(),
        ];
    }

    /** Ce qui s'est ouvert sur la période : collectes et comptes. */
    private function creations(string $projectId, Carbon $debut, Carbon $fin): array
    {
        $cagnottes = DB::table(project_table('cagnottes'))
            ->where('project_id', $projectId)
            ->whereBetween('created_at', [$debut, $fin])
            // Le solde personnel s'ouvre tout seul au premier accès : le
            // compter ferait passer une visite pour une création.
            ->whereIn('type', ['cagnotte_ouverte', 'tontine_periodique'])
            ->count();

        $comptes = Schema::hasTable('users')
            ? DB::table('users')
                ->where('project_id', $projectId)
                ->whereBetween('created_at', [$debut, $fin])
                ->count()
            : null;

        return ['collectes' => $cagnottes, 'comptes' => $comptes];
    }
}
