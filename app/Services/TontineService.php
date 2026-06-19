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
        // Seules les tontines actives avec une périodicité définie ont une prochaine date.
        if ($c->statut !== 'en_cours' || ! $c->periodicite) {
            return null;
        }

        // Intervalle = nombre de semaines/mois entre deux payouts (défaut 1).
        $intervalle = (int) ($c->intervalle ?? 1);
        // Toujours raisonner en heure de Libreville (UTC+1, pas de changement d'heure).
        $now        = now()->timezone('Africa/Libreville')->startOfDay();

        if ($c->periodicite === 'hebdomadaire' && $c->jour_semaine) {
            // Correspondance nom français du jour → constante Carbon (ISO 1-7).
            $dowMap = [
                'lundi'    => Carbon::MONDAY,    'mardi'    => Carbon::TUESDAY,
                'mercredi' => Carbon::WEDNESDAY, 'jeudi'    => Carbon::THURSDAY,
                'vendredi' => Carbon::FRIDAY,    'samedi'   => Carbon::SATURDAY,
                'dimanche' => Carbon::SUNDAY,
            ];
            // Si le jour configuré est inconnu, on replie sur vendredi par défaut.
            $targetDow = $dowMap[$c->jour_semaine] ?? Carbon::FRIDAY;

            if ($c->date_demarrage) {
                // Calcul déterministe : on part du premier retrait ancré sur date_demarrage.
                $debut          = Carbon::parse($c->date_demarrage)->timezone('Africa/Libreville')->startOfDay();
                $premierRetrait = $debut->copy();
                // Si la date de démarrage n'est pas le bon jour de la semaine,
                // on avance jusqu'au prochain jour cible.
                if ($premierRetrait->dayOfWeek !== $targetDow) {
                    $premierRetrait->next($targetDow);
                }
                // Payout N = premierRetrait + N cycles × intervalle semaines.
                $prochainRetrait = $premierRetrait->copy()->addWeeks($cyclesCompletes * $intervalle);
                // Si la date calculée est déjà passée, on avance d'un intervalle.
                if ($prochainRetrait->lt($now)) {
                    $prochainRetrait->addWeeks($intervalle);
                }
                return $prochainRetrait->toDateString();
            }

            // Fallback sans date_demarrage : prochaine occurrence du jour cible.
            $prochain = $now->copy();
            if ($prochain->dayOfWeek !== $targetDow) {
                $prochain->next($targetDow);
            }
            return $prochain->toDateString();
        }

        if ($c->periodicite === 'mensuelle' && $c->jour_mois) {
            $jourMois = (int) $c->jour_mois; // Jour du mois du payout (ex : 5 = le 5 de chaque mois).

            if ($c->date_demarrage) {
                $debut          = Carbon::parse($c->date_demarrage)->timezone('Africa/Libreville')->startOfDay();
                // Premier retrait : même mois que debut, au jour_mois configuré.
                $premierRetrait = $debut->copy()->setDay($jourMois);
                // Si le jour du premier retrait est avant la date de démarrage,
                // on décale d'un intervalle pour ne pas payer en retard.
                if ($premierRetrait->lt($debut)) {
                    $premierRetrait->addMonths($intervalle);
                }
                // Payout N = premierRetrait + N cycles × intervalle mois.
                $prochainRetrait = $premierRetrait->copy()->addMonths($cyclesCompletes * $intervalle);
                if ($prochainRetrait->lt($now)) {
                    $prochainRetrait->addMonths($intervalle);
                }
                return $prochainRetrait->toDateString();
            }

            // Fallback : si ce mois le jour n'est pas encore passé → ce mois-ci,
            // sinon → le même jour le mois prochain (× intervalle).
            $prochain = $now->day <= $jourMois
                ? $now->copy()->setDay($jourMois)
                : $now->copy()->addMonths($intervalle)->setDay($jourMois);
            return $prochain->toDateString();
        }

        // Périodicité non gérée (ex : quotidienne sans implémentation) → null.
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
        // Si la pénalité n'est pas configurée sur cette cagnotte, retour immédiat à 0.
        if (! $c->penalite_active || ! $c->penalite_montant || ! $c->penalite_frequence) {
            return 0;
        }

        $prochaineDateStr = $this->prochaineDate($c, $cyclesCompletes);
        if (! $prochaineDateStr) {
            return 0;
        }

        // Deadline = jour du retrait à 20h00 heure de Libreville.
        // C'est l'heure à laquelle le cron de payout tourne (TraiterRetraitsTontines).
        $deadline = Carbon::parse($prochaineDateStr)
            ->timezone('Africa/Libreville')
            ->setTime(20, 0, 0);

        $now = now()->timezone('Africa/Libreville');

        if ($now->lte($deadline)) {
            return 0; // Pas encore en retard — aucune pénalité.
        }

        // Nombre de secondes de retard depuis la deadline.
        $diffSeconds = $deadline->diffInSeconds($now);

        // Nombre de périodes complètes (ceil = on compte la période en cours).
        $periodes = match ($c->penalite_frequence) {
            'heure' => (int) ceil($diffSeconds / 3600),  // Ex : 90 min = 2 périodes horaires.
            'jour'  => (int) ceil($diffSeconds / 86400), // Ex : 25 h = 2 périodes journalières.
            default => 0,
        };

        // Pénalité totale = nombre de périodes × montant unitaire de pénalité.
        return $periodes * (int) $c->penalite_montant;
    }
}
