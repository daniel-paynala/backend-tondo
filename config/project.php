<?php

// Configuration multi-tenant du projet courant (Tondo/Tonji).
//
// L'architecture héberge plusieurs projets sur une même instance Supabase :
// chaque projet a un `slug` (registry `projects`) et un préfixe de table.
//
// Le préfixe et le slug sont pilotés par l'environnement pour permettre
// des noms différents entre dev et prod SANS toucher au code :
//   - dev/test : tables `tondo_*`, slug « tondo » (défauts historiques)
//   - prod     : tables `tonji_*`, slug « tonji »  (marque actuelle)
//
// On ne change QUE la valeur dans le `.env` de prod — voir database/supabase/PROD_SETUP.md.

return [
    // Préfixe appliqué aux tables métier scoped-projet (tondo_cagnottes → …).
    // Les tables PARTAGÉES (`projects`, `users`) et l'infra Laravel
    // (`migrations`, `cache`, `jobs`…) ne portent JAMAIS ce préfixe.
    'table_prefix' => env('DB_TABLE_PREFIX', 'tondo_'),

    // Slug du projet dans la registry `projects` (résolution du project_id).
    'slug' => env('PROJECT_SLUG', 'tondo'),
];
