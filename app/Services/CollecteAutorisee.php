<?php

namespace App\Services;

use App\Models\TondoOrganisation;
use App\Models\TondoUser;
use App\Support\CollecteGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une cagnotte accepte-t-elle une cotisation, à cet instant ?
 *
 * **Un seul endroit décide**, pour la même raison que
 * {@see SortiesAutorisees} côté sorties : l'app, le web, le bot WhatsApp et
 * l'USSD font tous entrer de l'argent, et recopier la règle garantit qu'un
 * canal finisse par l'oublier.
 *
 * Ce service existe d'ailleurs parce que c'était déjà le cas : le contrôleur
 * mobile et le service WhatsApp portaient chacun leur copie privée de la même
 * méthode `collecteBloquee()`, avec les mêmes lectures. Deux copies, deux
 * occasions de diverger.
 *
 * Il ne fait que les lectures en base ; la décision reste dans
 * {@see CollecteGuard}, pure et testable sans base.
 */
class CollecteAutorisee
{
    public function __construct(
        private readonly TondoConfigService $config,
        private readonly CapacitesOperateur $capacites,
    ) {}

    /**
     * Message de refus à rendre au cotisant, ou null si la collecte est ouverte.
     *
     * @param  object $cagnotte  Doit porter user_id, project_id, visibilite,
     *                           statut_validation, type, date_fin et
     *                           cotisation_bloquee.
     */
    public function refus(mixed $cagnotte, mixed $cotisant = null): ?string
    {
        // ── Écosystème et moyen de paiement ─────────────────────────────────
        //
        // Deux questions distinctes, et dans cet ordre.
        //
        // 1. Le cotisant est-il du MÊME PAYS que la collecte ? Les écosystèmes
        //    sont étanches (décision du 2026-10-14) : XAF et XOF sont deux
        //    monnaies sous deux banques centrales, et une cotisation d'un pays
        //    à l'autre serait un transfert transfrontalier que nous ne savons
        //    pas assumer.
        //
        // 2. Son pays sait-il encaisser ? Sans intégration locale, le bouton
        //    doit le dire plutôt que d'ouvrir un formulaire qui échouera.
        //
        // Le cotisant est facultatif : certains appelants n'évaluent que l'état
        // de la collecte elle-même, sans savoir qui regarde.
        if ($cotisant !== null && ($refusPays = $this->refusPays($cagnotte, $cotisant)) !== null) {
            return $refusPays;
        }

        $gerant    = TondoUser::find($cagnotte->user_id);
        $orgStatut = null;

        if ($gerant && $gerant->type_compte === 'association') {
            $orgStatut = TondoOrganisation::query()
                ->where('project_id', $cagnotte->project_id)
                ->where('user_id', $gerant->id)
                ->value('statut');
        }

        // Organisateur suspendu : la collecte se ferme comme si la cagnotte
        // elle-même était verrouillée.
        //
        // Même message qu'un verrou de collecte, volontairement : le cotisant
        // n'a pas à apprendre qu'un organisateur est suspendu. Ce qu'il doit
        // savoir, c'est que ça ne passera pas et vers qui se tourner.
        $suspendu = $gerant?->suspendu_at !== null;

        return CollecteGuard::bloquee(
            $cagnotte->visibilite,
            $cagnotte->statut_validation,
            $gerant?->type_compte,
            $orgStatut,
            $cagnotte->type,
            $cagnotte->date_fin ? (string) $cagnotte->date_fin : null,
            (bool) ($cagnotte->cotisation_bloquee ?? false) || $suspendu,
            $this->verrouGlobal($cagnotte->project_id, $gerant?->type_compte),
        );
    }

    /**
     * Le cotisant peut-il verser dans CETTE collecte, depuis son pays ?
     *
     * La comparaison porte sur le pays, jamais sur l'indicatif : celui-ci
     * cohabitait sous deux formes en base — « +241 » et « 241 » — et une
     * comparaison ratée laisse passer, c'est-à-dire tombe du mauvais côté.
     */
    private function refusPays(mixed $cagnotte, mixed $cotisant): ?string
    {
        $paysCotisant = $cotisant->pays ?? null;
        $paysCollecte = $cagnotte->pays ?? null;

        if ($paysCotisant === null) {
            return 'Votre compte n\'est rattaché à aucun pays. Contactez le support.';
        }

        // Une collecte d'avant le rattachement n'a pas de pays en colonne. La
        // laisser passer l'ouvrirait à TOUS les pays qui encaissent — le trou
        // exact que l'étanchéité cherche à fermer. On retombe donc sur son
        // organisateur, qui est la source dont la colonne est elle-même
        // recopiée : une collecte appartient à l'écosystème de qui l'a créée.
        $paysCollecte ??= $this->paysDeLOrganisateur($cagnotte);

        // Si même son organisateur n'est rattaché à rien, on ferme. Un pays
        // qu'on ne sait pas nommer ne peut pas être comparé, et laisser passer
        // reviendrait à ouvrir la frontière faute de savoir où elle est.
        if ($paysCollecte === null) {
            return 'Cette collecte est en cours de régularisation. Contactez le support.';
        }

        if ($paysCollecte !== $paysCotisant) {
            return 'Cette collecte n\'est pas accessible depuis votre pays.';
        }

        // Au niveau du PAYS, pas d'un opérateur précis : savoir si l'écran
        // s'ouvre ne demande pas de choisir par quel opérateur on paiera.
        // Ce choix vient après, au moment de payer.
        if (! $this->capacites->pourLePays((string) $cagnotte->project_id, $paysCotisant)['payin']) {
            return 'Aucun moyen de paiement local pour le moment.';
        }

        return null;
    }

    /**
     * Le pays de l'organisateur, quand la collecte ne le porte pas encore.
     *
     * Lecture directe plutôt que par la relation : ce chemin sert aux
     * collectes anciennes, et la relation n'est pas toujours chargée là où le
     * refus est évalué. Toute absence — table, colonne, ligne — rend `null`,
     * et c'est l'appelant qui ferme.
     */
    private function paysDeLOrganisateur(mixed $cagnotte): ?string
    {
        $userId = $cagnotte->user_id ?? null;

        if ($userId === null || ! Schema::hasTable('users') || ! Schema::hasColumn('users', 'pays')) {
            return null;
        }

        return DB::table('users')->where('id', $userId)->value('pays');
    }

    /**
     * Verrou global du type de compte.
     *
     * Un type inconnu n'est pas bloqué : la config ne décrit que les types
     * qu'elle connaît, et refuser par défaut couperait la collecte à la
     * première valeur inattendue.
     */
    private function verrouGlobal(string $projectId, ?string $typeCompte): bool
    {
        if ($typeCompte === null) {
            return false;
        }

        $cfg = $this->config->getOperatorConfig($projectId);

        return (bool) ($cfg['cotisations_bloquees'][$typeCompte] ?? false);
    }
}
