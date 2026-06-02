<?php

namespace App\Services;

use App\Models\TondoCagnotte;
use Carbon\Carbon;

/**
 * Logique métier partagée entre CagnottesController (API show) et
 * TraiterRetraitsTontines (cron 20h).
 */
class TontineService
{
    /**
     * Calcule la prochaine date de retrait d'une tontine en cours.
     *
     * Ancre sur date_demarrage quand disponible pour suivre tous les cycles
     * de façon déterministe. Fallback "prochaine occurrence depuis aujourd'hui"
     * pour les tontines sans date_demarrage (données antérieures à la migration).
     *
     * @param  TondoCagnotte $c
     * @param  int           $cyclesCompletes  Nombre de payouts succes pour cette cagnotte.
     * @return string|null   Date ISO (YYYY-MM-DD) ou null si calcul impossible.
     */
    public function prochaineDate(TondoCagnotte $c, int $cyclesCompletes): ?string
    {
        if ($c->statut !== 'en_cours' || ! $c->periodicite) {
            return null;
        }

        $intervalle = (int) ($c->intervalle ?? 1);
        $now        = now()->timezone('Africa/Libreville')->startOfDay();

        if ($c->periodicite === 'hebdomadaire' && $c->jour_semaine) {
            $dowMap = [
                'lundi'    => Carbon::MONDAY,    'mardi'    => Carbon::TUESDAY,
                'mercredi' => Carbon::WEDNESDAY, 'jeudi'    => Carbon::THURSDAY,
                'vendredi' => Carbon::FRIDAY,    'samedi'   => Carbon::SATURDAY,
                'dimanche' => Carbon::SUNDAY,
            ];
            $targetDow = $dowMap[$c->jour_semaine] ?? Carbon::FRIDAY;

            if ($c->date_demarrage) {
                $debut          = Carbon::parse($c->date_demarrage)->timezone('Africa/Libreville')->startOfDay();
                $premierRetrait = $debut->copy();
                if ($premierRetrait->dayOfWeek !== $targetDow) {
                    $premierRetrait->next($targetDow);
                }
                $prochainRetrait = $premierRetrait->copy()->addWeeks($cyclesCompletes * $intervalle);
                if ($prochainRetrait->lt($now)) {
                    $prochainRetrait->addWeeks($intervalle);
                }
                return $prochainRetrait->toDateString();
            }

            $prochain = $now->copy();
            if ($prochain->dayOfWeek !== $targetDow) {
                $prochain->next($targetDow);
            }
            return $prochain->toDateString();
        }

        if ($c->periodicite === 'mensuelle' && $c->jour_mois) {
            $jourMois = (int) $c->jour_mois;

            if ($c->date_demarrage) {
                $debut          = Carbon::parse($c->date_demarrage)->timezone('Africa/Libreville')->startOfDay();
                $premierRetrait = $debut->copy()->setDay($jourMois);
                if ($premierRetrait->lt($debut)) {
                    $premierRetrait->addMonths($intervalle);
                }
                $prochainRetrait = $premierRetrait->copy()->addMonths($cyclesCompletes * $intervalle);
                if ($prochainRetrait->lt($now)) {
                    $prochainRetrait->addMonths($intervalle);
                }
                return $prochainRetrait->toDateString();
            }

            $prochain = $now->day <= $jourMois
                ? $now->copy()->setDay($jourMois)
                : $now->copy()->addMonths($intervalle)->setDay($jourMois);
            return $prochain->toDateString();
        }

        return null;
    }

    /**
     * Calcule le montant de pénalité actuellement dû pour une tontine en retard.
     *
     * La deadline d'un cycle est : prochaineDate à 20h00 (Africa/Libreville).
     * Si le participant paie après cette heure, la pénalité s'accumule par
     * heure ou par jour selon `penalite_frequence`.
     *
     * La pénalité n'est pas soumise aux frais Paynala/Airtel — elle s'ajoute
     * telle quelle au montant de base.
     *
     * @return int  Montant de pénalité en FCFA (0 si pas en retard ou pas de pénalité).
     */
    public function calculerPenalite(TondoCagnotte $c, int $cyclesCompletes): int
    {
        if (! $c->penalite_active || ! $c->penalite_montant || ! $c->penalite_frequence) {
            return 0;
        }

        $prochaineDateStr = $this->prochaineDate($c, $cyclesCompletes);
        if (! $prochaineDateStr) {
            return 0;
        }

        // Deadline = jour du retrait à 20h00 heure de Libreville.
        $deadline = Carbon::parse($prochaineDateStr)
            ->timezone('Africa/Libreville')
            ->setTime(20, 0, 0);

        $now = now()->timezone('Africa/Libreville');

        if ($now->lte($deadline)) {
            return 0; // Pas encore en retard
        }

        $diffSeconds = $deadline->diffInSeconds($now);

        $periodes = match ($c->penalite_frequence) {
            'heure' => (int) ceil($diffSeconds / 3600),
            'jour'  => (int) ceil($diffSeconds / 86400),
            default => 0,
        };

        return $periodes * (int) $c->penalite_montant;
    }
}
