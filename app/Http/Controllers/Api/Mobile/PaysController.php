<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\CapacitesOperateur;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Les pays où Tonji est servi, pour l'écran d'inscription.
 *
 * L'application affichait « +241 » en dur à trois endroits. Tant qu'un seul
 * pays existait, personne ne le voyait ; au deuxième, plus aucun compte
 * étranger ne pouvait être créé — le numéro était réécrit en numéro gabonais
 * avant même d'atteindre la moindre vérification.
 *
 * La liste vient donc du SERVEUR, pas d'une constante dans l'app : ouvrir un
 * pays depuis le dashboard doit suffire à le voir apparaître, sans publier une
 * nouvelle version sur les magasins.
 */
class PaysController extends Controller
{
    /**
     * Les noms affichés, par code ISO.
     *
     * Un code absent se montre tel quel plutôt que de disparaître : mieux vaut
     * un « SN » brut dans la liste qu'un pays ouvert en base et invisible à
     * l'écran faute de traduction.
     */
    private const NOMS = [
        'GA' => 'Gabon',
        'SN' => 'Sénégal',
        'CM' => 'Cameroun',
        'CI' => "Côte d'Ivoire",
        'CG' => 'Congo',
        'BJ' => 'Bénin',
        'TG' => 'Togo',
        'ML' => 'Mali',
        'BF' => 'Burkina Faso',
        'NE' => 'Niger',
    ];

    /**
     * GET /api/mobile/pays
     *
     * Réponse : { pays: [ { code, nom, indicatif, inscription_possible } ] }
     *
     * `inscription_possible` est TOUJOURS vrai ici, et c'est voulu : un pays
     * sans intégration de paiement accepte quand même les inscriptions, en
     * mode dégradé — e-mail obligatoire et code envoyé par les deux canaux.
     * Ce sont les écrans d'argent qui se ferment ensuite, pas la porte
     * d'entrée. Le champ reste exposé pour que l'app puisse prévenir sans
     * avoir à deviner.
     */
    public function index(): JsonResponse
    {
        $projectId = Project::tondoId();
        $registre  = app(CapacitesOperateur::class);

        $lignes = DB::table(project_table('project_config'))
            ->where('project_id', $projectId)
            // Un opérateur désactivé ne doit pas ouvrir un pays à lui seul,
            // mais `actif` est nullable sur les lignes anciennes : les traiter
            // comme éteintes ferait disparaître le Gabon.
            ->where(function ($q) {
                $q->whereNull('actif')->orWhere('actif', true);
            })
            ->whereNotNull('indicatif')
            ->get(['pays', 'indicatif']);

        $pays = $lignes
            ->filter(fn ($l) => ! empty($l->pays))
            ->groupBy('pays')
            ->map(function ($lignes, $code) use ($registre, $projectId) {
                // Un pays peut avoir plusieurs opérateurs ; l'indicatif est le
                // même pour tous, on prend le premier renseigné.
                $indicatif = ltrim((string) $lignes->first()->indicatif, '+');

                return [
                    'code'      => $code,
                    'nom'       => self::NOMS[$code] ?? $code,
                    'indicatif' => '+' . $indicatif,
                    // Ce que le pays sait faire, cumulé sur ses opérateurs.
                    // L'app s'en sert pour prévenir AVANT la saisie plutôt que
                    // de laisser découvrir un bouton éteint après l'inscription.
                    'encaisse'  => $registre->pourLePays($projectId, $code)['payin'],
                    'reverse'   => $registre->pourLePays($projectId, $code)['payout'],
                    'inscription_possible' => true,
                ];
            })
            ->sortBy('nom')
            ->values();

        return response()->json(['pays' => $pays]);
    }
}
