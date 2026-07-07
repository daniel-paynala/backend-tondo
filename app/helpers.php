<?php

// Helpers globaux de l'application (chargés via composer autoload "files").

use Illuminate\Support\Facades\DB;

if (! function_exists('project_table')) {
    /**
     * Nom complet d'une table métier scoped-projet, préfixe inclus.
     *
     * Le préfixe vient de config('project.table_prefix') — donc `tondo_` en
     * dev et `tonji_` en prod, sans rien coder en dur ailleurs.
     *
     * Exemple : project_table('cagnottes') → "tondo_cagnottes" (dev)
     *                                      → "tonji_cagnottes" (prod)
     *
     * NE PAS utiliser pour les tables partagées (`projects`, `users`) ni
     * pour l'infra Laravel — elles n'ont pas de préfixe projet.
     *
     * @param  string  $suffix  Nom de la table sans préfixe (ex : 'cagnottes').
     * @return string           Nom complet préfixé.
     */
    function project_table(string $suffix): string
    {
        return config('project.table_prefix').$suffix;
    }
}

if (! function_exists('project_query')) {
    /**
     * Raccourci : query builder sur une table métier scoped-projet.
     *
     * project_query('participants') ≡ DB::table(project_table('participants')).
     *
     * @param  string  $suffix  Nom de la table sans préfixe.
     * @return \Illuminate\Database\Query\Builder
     */
    function project_query(string $suffix)
    {
        return DB::table(project_table($suffix));
    }
}
