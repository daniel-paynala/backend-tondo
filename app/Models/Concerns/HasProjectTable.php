<?php

namespace App\Models\Concerns;

/**
 * Trait pour les modèles dont la table est une table métier scoped-projet
 * (préfixée `tondo_` en dev / `tonji_` en prod).
 *
 * Le modèle déclare seulement le suffixe :
 *
 *     class TondoCagnotte extends Model
 *     {
 *         use HasProjectTable;
 *         protected string $tableSuffix = 'cagnottes';
 *     }
 *
 * Le préfixe est résolu à l'exécution via config('project.table_prefix'),
 * donc aucun nom de table en dur — le même code marche en dev et en prod.
 *
 * NB : la propriété `$tableSuffix` est déclarée par CHAQUE modèle (pas par le
 * trait) — PHP 8.2+ interdit qu'un trait et sa classe déclarent la même
 * propriété avec des valeurs par défaut différentes.
 *
 * @property string $tableSuffix Nom de la table sans préfixe (ex : 'cagnottes').
 */
trait HasProjectTable
{
    /**
     * Résout le nom complet de la table en préfixant le suffixe déclaré
     * par le modèle.
     */
    public function getTable(): string
    {
        return project_table($this->tableSuffix);
    }
}
