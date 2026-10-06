<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Carnet des commerces payables — **une seule lecture pour tous les canaux**.
 *
 * L'app lit ce carnet par l'API, le web par la même API, et le bot WhatsApp
 * directement. Les trois doivent voir exactement les mêmes fiches, le même
 * taux résolu et la même règle de résolution d'une saisie : sans quoi un
 * commerce payable depuis l'app serait introuvable depuis WhatsApp, ou pire,
 * afficherait un taux différent de celui qui sera prélevé.
 *
 * Le taux renvoyé est TOUJOURS le taux résolu — celui de la fiche s'il en
 * porte un, sinon celui du projet. Aucun appelant n'a à connaître l'existence
 * des taux négociés.
 */
class CarnetMarchands
{
    public function __construct(private readonly TondoConfigService $config) {}

    /**
     * Fiches actives du projet, éventuellement filtrées par nom.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function actifs(string $projectId, ?string $recherche = null): Collection
    {
        $marchands  = project_table('marchands');
        $categories = project_table('categories_marchands');

        $lignes = $this->requete($projectId)
            ->when(filled($recherche), function ($q) use ($recherche, $marchands) {
                $q->where("{$marchands}.nom", 'ilike', '%' . trim((string) $recherche) . '%');
            })
            ->orderBy("{$marchands}.nom")
            ->get(self::colonnes($marchands, $categories));

        $taux = $this->tauxProjet($projectId);

        return $lignes->map(fn ($m) => self::presenter($m, $taux));
    }

    /**
     * Fiches correspondant à une saisie : un code d'enseigne ou un numéro.
     *
     * On ne devine pas lequel des deux a été tapé — les deux pistes sont
     * cherchées. **Plusieurs réponses sont normales** : une chaîne encaisse sur
     * un seul numéro pour plusieurs points de vente, le numéro seul ne dit donc
     * pas QUI est payé, et c'est précisément ce que le code lève. Quand
     * plusieurs fiches répondent, c'est à l'utilisateur de désigner celle qu'il
     * paie — aucun canal ne choisit à sa place.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function resoudre(string $projectId, string $saisie): Collection
    {
        $saisie = trim($saisie);
        if ($saisie === '') {
            return collect();
        }

        // Les champs clients sont sans indicatif : un numéro arrive en 9
        // chiffres locaux, alors que les fiches stockent l'E.164. La forme
        // internationale est acceptée, au cas où elle serait collée d'ailleurs.
        $numero = null;
        if (preg_match('/^0\d{8}$/', $saisie)) {
            $numero = '+241' . substr($saisie, 1);
        } elseif (preg_match('/^\+241\d{8,9}$/', $saisie)) {
            $numero = $saisie;
        }

        $marchands  = project_table('marchands');
        $categories = project_table('categories_marchands');

        $lignes = $this->requete($projectId)
            ->where(function ($q) use ($marchands, $saisie, $numero) {
                // Code comparé sans tenir compte de la casse : le client le
                // tape comme il l'a lu sur une devanture ou un reçu.
                $q->whereRaw("upper({$marchands}.code_marchand) = ?", [mb_strtoupper($saisie)]);
                if ($numero !== null) {
                    $q->orWhere("{$marchands}.numero_tel", $numero);
                }
            })
            ->orderBy("{$marchands}.nom")
            ->get(self::colonnes($marchands, $categories));

        $taux = $this->tauxProjet($projectId);

        return $lignes->map(fn ($m) => self::presenter($m, $taux))->values();
    }

    /**
     * Fiche brute pour un décaissement, ou null si elle n'est plus payable.
     *
     * Renvoie l'objet de base et non la forme présentée : {@see SortieArgent} a
     * besoin de `type_paynala`, qui n'est pas une donnée à montrer au client.
     * La vérification `actif` est faite ici, au moment du paiement — une fiche
     * désactivée entre l'affichage et la validation ne doit pas encaisser.
     */
    public function fiche(string $projectId, string $id): ?object
    {
        $marchand = DB::table(project_table('marchands'))
            ->where('project_id', $projectId)
            ->where('id', $id)
            ->first(['id', 'nom', 'numero_tel', 'actif', 'type_paynala', 'frais_taux']);

        return ($marchand && $marchand->actif) ? $marchand : null;
    }

    /**
     * Taux de frais du projet — repli quand la fiche n'en porte pas.
     *
     * Sert à ANNONCER le prélèvement au client, pas à le calculer : c'est
     * Paynala qui prélève, d'après le type B2B/B2C transmis. Ce taux doit donc
     * refléter le leur — rien côté Tonji ne le garantit.
     */
    public function tauxProjet(string $projectId): float
    {
        return (float) ($this->config->getOperatorConfig($projectId)['frais_marchand'] ?? 0);
    }

    /** Base commune des deux lectures : les fiches actives du projet. */
    private function requete(string $projectId): \Illuminate\Database\Query\Builder
    {
        $marchands  = project_table('marchands');
        $categories = project_table('categories_marchands');

        return DB::table($marchands)
            ->leftJoin($categories, "{$categories}.id", '=', "{$marchands}.categorie_id")
            ->where("{$marchands}.project_id", $projectId)
            ->where("{$marchands}.actif", true);
    }

    /**
     * Colonnes lues pour une fiche — identiques dans les deux lectures.
     *
     * @return array<int, string>
     */
    private static function colonnes(string $marchands, string $categories): array
    {
        return [
            "{$marchands}.id",
            "{$marchands}.nom",
            "{$marchands}.code_marchand",
            "{$marchands}.frais_taux",
            "{$marchands}.numero_tel",
            "{$marchands}.titulaire",
            "{$marchands}.ville",
            "{$categories}.libelle as categorie",
        ];
    }

    /**
     * Forme rendue aux clients. Une seule définition : la liste du carnet et la
     * résolution par saisie doivent rendre exactement la même chose, sinon un
     * canal aurait deux façons de lire un marchand.
     *
     * @return array<string, mixed>
     */
    private static function presenter(object $m, float $tauxProjet): array
    {
        return [
            'id'        => $m->id,
            'nom'       => $m->nom,
            'code'      => $m->code_marchand,
            // Taux RÉSOLU, pas le brut : le client n'a pas à savoir qu'il
            // existe un taux de projet et d'éventuelles exceptions négociées.
            // Il voit ce qui sera prélevé pour CE commerce.
            //
            // `??` et non `?:` — zéro est une exonération, pas une absence.
            'frais'     => (float) ($m->frais_taux ?? $tauxProjet),
            // Numéro affiché : c'est ce qui sera crédité, le client doit
            // pouvoir le lire avant de valider.
            'numero'    => $m->numero_tel,
            'titulaire' => $m->titulaire,
            'ville'     => $m->ville,
            'categorie' => $m->categorie,
        ];
    }
}
