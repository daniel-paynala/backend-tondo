<?php

namespace App\Services;

use App\Models\TondoOrganisation;
use App\Models\TondoUser;
use App\Support\CollecteGuard;

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
    public function __construct(private readonly TondoConfigService $config) {}

    /**
     * Message de refus à rendre au cotisant, ou null si la collecte est ouverte.
     *
     * @param  object $cagnotte  Doit porter user_id, project_id, visibilite,
     *                           statut_validation, type, date_fin et
     *                           cotisation_bloquee.
     */
    public function refus(mixed $cagnotte): ?string
    {
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
