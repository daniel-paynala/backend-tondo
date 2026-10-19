<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Jusqu'à quand une cagnotte ouverte peut vivre.
 *
 * Une cagnotte ouverte sans fin dérive : l'argent dort sur un solde que
 * personne ne réclame, et l'organisateur finit par ne plus savoir qu'elle
 * existe. On lui donne donc une durée maximale, en JOURS, réglée depuis le
 * back-office. À l'échéance, les fonds partent sur le numéro de retrait et la
 * cagnotte se ferme — par le mode « date » du reversement automatique, qui
 * sait déjà faire exactement cela.
 *
 * La règle tient en trois temps, et tout le reste du code s'y réfère plutôt
 * que de la recopier.
 *
 * 1. **L'échéance est DÉRIVÉE** : `date_creation + durée`. Elle est recalculée
 *    à chaque passage, ce qui la rend glissante — porter le réglage de six
 *    mois à un an rallonge tout le parc au passage suivant, sans toucher une
 *    seule ligne à la main.
 *
 * 2. **Une cagnotte déjà trop vieille n'est pas fermée le soir même.** Quand
 *    le calcul tombe dans le passé, on pose une échéance à trente jours pour
 *    que la série de rappels se déroule. Cette échéance-là est COLLANTE : la
 *    recalculer chaque jour la repousserait indéfiniment, et la cagnotte ne se
 *    fermerait jamais.
 *
 * 3. **La durée ne fait que PLAFONNER.** Un organisateur qui s'est donné une
 *    date de fin plus proche la garde : c'est son choix, et il est plus strict
 *    que le nôtre.
 *
 * Les tontines sont hors de portée (décidé le 2026-10-19) : leur durée tient
 * au nombre de participants multiplié par la périodicité. Les couper à N jours
 * fermerait un cycle en cours et laisserait des membres qui ont cotisé sans
 * avoir reçu leur tour.
 */
class EcheanceCagnotte
{
    /** Seul ce type est concerné. */
    public const TYPE_CONCERNE = 'cagnotte_ouverte';

    /**
     * Les rappels, en jours restants avant la clôture.
     *
     * Du plus lointain au plus proche : c'est l'ordre dans lequel ils partent,
     * et il rend la lecture des journaux évidente.
     */
    public const PALIERS = [30, 14, 7, 3, 1];

    /**
     * À partir de J-7, le rappel part AUSSI par courriel quand une adresse est
     * connue (règle de Daniel, 2026-10-19).
     *
     * Les deux premiers paliers restent en push seul : à un mois, l'échéance
     * n'est pas une urgence, et un courriel de plus se range sans être lu.
     */
    public const PALIERS_AVEC_EMAIL = [7, 3, 1];

    /** Le répit laissé à une cagnotte déjà plus vieille que la durée fixée. */
    public const GRACE_JOURS = 30;

    public function __construct(private readonly TondoConfigService $config) {}

    /**
     * La durée maximale réglée, en jours. `null` = aucune limite.
     *
     * L'absence de réglage ne vaut PAS zéro : poser la colonne ne doit fermer
     * aucune cagnotte tant que personne n'a décidé d'une durée.
     */
    public function dureeMax(string $projectId): ?int
    {
        // Mémorisé : le sérialiseur des collectes appelle ceci une fois par
        // cagnotte, et une liste de trente en ferait trente requêtes pour une
        // valeur qui ne bouge pas au sein d'une même requête.
        if (array_key_exists($projectId, $this->dureeParProjet)) {
            return $this->dureeParProjet[$projectId];
        }

        $jours = $this->config->getOperatorConfig($projectId)['duree_max_cagnotte_jours'] ?? null;

        return $this->dureeParProjet[$projectId] = $jours ? (int) $jours : null;
    }

    /** @var array<string, ?int> */
    private array $dureeParProjet = [];

    /**
     * La date de clôture à MONTRER, même si rien n'est encore écrit en base.
     *
     * `date_echeance` n'est posée que par le passage quotidien. S'en tenir à
     * elle ferait qu'un réglage posé le matin, ou une cagnotte créée dans la
     * journée, n'afficheraient aucune date avant le lendemain — l'utilisateur
     * réglerait quelque chose et ne verrait rien.
     *
     * Le calcul rendu ici est exactement celui que le passage écrira, grâce
     * compris. La colonne reste la matérialisation : c'est elle, et elle
     * seule, qui fait foi pour FERMER — sans quoi une cagnotte déjà trop
     * vieille serait close le soir même, avant que le moindre rappel ne soit
     * parti.
     */
    public function pourAffichage(mixed $cagnotte, ?int $jours, Carbon $aujourdhui): ?string
    {
        if (! empty($cagnotte->date_echeance)) {
            return $this->effective($cagnotte);
        }

        $dates = [];

        if (! empty($cagnotte->date_fin)) {
            $dates[] = Carbon::parse($cagnotte->date_fin)
                ->timezone('Africa/Libreville')->toDateString();
        }

        if (($calculee = $this->calculer($cagnotte, $jours, $aujourdhui)) !== null) {
            $dates[] = $calculee;
        }

        return $dates ? min($dates) : null;
    }

    /**
     * L'échéance à inscrire sur cette cagnotte, ou `null` s'il n'y en a pas.
     *
     * @param  mixed   $cagnotte     doit porter `type`, `date_creation` et `date_echeance`
     * @param  ?int    $jours        la durée réglée
     * @param  Carbon  $aujourdhui   la date du jour, passée pour rester testable
     */
    public function calculer(mixed $cagnotte, ?int $jours, Carbon $aujourdhui): ?string
    {
        if ($jours === null || ($cagnotte->type ?? null) !== self::TYPE_CONCERNE) {
            return null;
        }

        $creation = $cagnotte->date_creation ?? null;
        if ($creation === null) {
            // Sans date de création, rien à compter. On s'abstient plutôt que
            // de dater depuis aujourd'hui, ce qui donnerait à une vieille
            // cagnotte une seconde jeunesse à chaque passage.
            return null;
        }

        $theorique = Carbon::parse($creation)->timezone('Africa/Libreville')
            ->startOfDay()->addDays($jours);

        // Cas courant : l'échéance est devant nous. On la recalcule à chaque
        // fois, et c'est précisément ce qui rallonge le parc quand le réglage
        // s'allonge.
        if ($theorique->toDateString() > $aujourdhui->toDateString()) {
            return $theorique->toDateString();
        }

        // Le calcul tombe dans le passé. Si une échéance de grâce a déjà été
        // posée, on la garde — la repousser chaque jour empêcherait à jamais
        // la clôture.
        if (! empty($cagnotte->date_echeance)) {
            return Carbon::parse($cagnotte->date_echeance)->toDateString();
        }

        return $aujourdhui->copy()->addDays(self::GRACE_JOURS)->toDateString();
    }

    /**
     * La date qui fait foi pour clôturer : la plus PROCHE des deux.
     *
     * La durée maximale plafonne, elle ne prolonge pas. Un organisateur qui
     * s'est donné trois semaines garde ses trois semaines, même si le réglage
     * autorise un an.
     */
    public function effective(mixed $cagnotte): ?string
    {
        $dates = [];

        foreach (['date_fin', 'date_echeance'] as $champ) {
            if (! empty($cagnotte->{$champ})) {
                $dates[] = Carbon::parse($cagnotte->{$champ})
                    ->timezone('Africa/Libreville')->toDateString();
            }
        }

        return $dates ? min($dates) : null;
    }

    /**
     * Le palier atteint aujourd'hui, ou `null` si aucun.
     *
     * Comparaison sur une ÉGALITÉ de jours restants, pas sur un intervalle :
     * un « moins de 7 jours » renverrait le même palier chaque jour de la
     * semaine, et l'index d'unicité n'en laisserait passer qu'un — celui du
     * premier jour, donc le plus lointain, jamais les rappels plus pressants.
     */
    public function palierDuJour(?string $echeance, Carbon $aujourdhui): ?int
    {
        if ($echeance === null) {
            return null;
        }

        // Converti en entier AVANT la comparaison : Carbon 3 rend un flottant,
        // et `7.0 !== 7` en comparaison stricte — aucun palier n'aurait jamais
        // correspondu, donc aucun rappel ne serait jamais parti.
        $restants = (int) $aujourdhui->copy()->startOfDay()
            ->diffInDays(Carbon::parse($echeance)->startOfDay(), false);

        return in_array($restants, self::PALIERS, true) ? $restants : null;
    }
}
