-- ============================================================================
-- etat_schema_prod.sql — LECTURE SEULE
--
-- Où en est le schéma de production ? À coller dans le SQL Editor du projet
-- PROD avant et après une passe de scripts.
--
-- Écrit après l'échec de `029` en production : il réclamait `tonji_payout.canal`,
-- que seul `028_tonji_retrait_agents.sql` ajoute. `013` n'ajoute `canal` qu'à
-- `tonji_payin` et `tonji_paiements` — d'où la confusion. Mieux vaut lire
-- l'état réel que déduire d'une liste de fichiers ce qui a été joué.
--
-- Ne modifie rien.
-- ============================================================================

SELECT 'colonne' AS objet, 'tonji_payout.canal'       AS nom,
       to_char(count(*), '9') AS present
  FROM information_schema.columns
 WHERE table_name = 'tonji_payout' AND column_name = 'canal'
UNION ALL SELECT 'colonne', 'tonji_payout.agent_id', to_char(count(*), '9')
  FROM information_schema.columns
 WHERE table_name = 'tonji_payout' AND column_name = 'agent_id'
UNION ALL SELECT 'colonne', 'tonji_payout.type_beneficiaire', to_char(count(*), '9')
  FROM information_schema.columns
 WHERE table_name = 'tonji_payout' AND column_name = 'type_beneficiaire'
UNION ALL SELECT 'colonne', 'tonji_payout.marchand_id', to_char(count(*), '9')
  FROM information_schema.columns
 WHERE table_name = 'tonji_payout' AND column_name = 'marchand_id'
UNION ALL SELECT 'table', 'tonji_agents', to_char(count(*), '9')
  FROM information_schema.tables WHERE table_name = 'tonji_agents'
UNION ALL SELECT 'table', 'tonji_supports_retrait', to_char(count(*), '9')
  FROM information_schema.tables WHERE table_name = 'tonji_supports_retrait'
UNION ALL SELECT 'table', 'tonji_partenaires_retrait', to_char(count(*), '9')
  FROM information_schema.tables WHERE table_name = 'tonji_partenaires_retrait'
UNION ALL SELECT 'table', 'tonji_retraits_especes', to_char(count(*), '9')
  FROM information_schema.tables WHERE table_name = 'tonji_retraits_especes'
UNION ALL SELECT 'table', 'tonji_marchands', to_char(count(*), '9')
  FROM information_schema.tables WHERE table_name = 'tonji_marchands'
 ORDER BY 1, 2;
