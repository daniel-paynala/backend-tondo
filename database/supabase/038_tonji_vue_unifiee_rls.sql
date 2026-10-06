-- ============================================================================
-- 038_tonji_vue_unifiee_rls.sql
-- La vue unifiée cesse de contourner la sécurité des lignes.
--
-- Signalé CRITIQUE par le linter Supabase après l'exécution de 029 :
-- « View public.tonji_transactions_unified is defined with the SECURITY
--   DEFINER property ».
--
-- ── Ce que c'était réellement ───────────────────────────────────────────────
--
-- Une vue PostgreSQL s'exécute par défaut avec les droits de son PROPRIÉTAIRE
-- (`postgres`), pas de celui qui l'interroge. La RLS des tables sources n'est
-- donc pas appliquée. Et par les droits par défaut d'un projet Supabase, cette
-- vue était accessible à `anon` et `authenticated` — vérifié : SELECT, et même
-- INSERT/UPDATE/DELETE, accordés aux deux.
--
-- Autrement dit : avec la clé anonyme, on lisait **toutes les transactions de
-- tous les projets**, la politique `project_id` étant sautée. La clé anonyme
-- d'un projet Supabase est faite pour être publique.
--
-- ── Pourquoi la correction ne casse rien ────────────────────────────────────
--
-- Les deux seuls lecteurs sont le backend Laravel (connexion `postgres`) et le
-- dashboard (`service_role`, côté serveur uniquement, jamais exposé au
-- navigateur). Les deux contournent la RLS par construction. Aucun client
-- n'embarque de clé Supabase : l'app et le web passent par l'API Laravel.
--
-- Deux gestes, et le second seul ne suffirait pas — un GRANT retiré se
-- re-accorde par les droits par défaut d'une future table :
--   1. `security_invoker` : la vue s'exécute avec les droits de l'appelant,
--      donc la RLS des tables sources s'applique ;
--   2. retrait des droits à `anon` et `authenticated`.
--
-- ⚠️ `029` a été corrigé dans le même mouvement pour ne pas réintroduire le
-- problème s'il était rejoué. Ce script-ci est pour les bases où 029 est DÉJÀ
-- passé.
--
-- Requiert PostgreSQL ≥ 15 (`security_invoker`). Supabase est en 17.
--
-- ⚠️ PROD (`tonji_`). En TEST, jouer `TEST_038_tondo_vue_unifiee_rls.sql`.
-- IDEMPOTENT. À jouer dans le SQL Editor Supabase.
-- ============================================================================

ALTER VIEW public.tonji_transactions_unified SET (security_invoker = on);

REVOKE ALL ON public.tonji_transactions_unified FROM anon;
REVOKE ALL ON public.tonji_transactions_unified FROM authenticated;

-- Les lecteurs légitimes, et eux seuls.
GRANT SELECT ON public.tonji_transactions_unified TO service_role;

COMMENT ON VIEW public.tonji_transactions_unified IS
  'Union des 3 tables transactionnelles. Lecture seule, réservée à service_role. security_invoker = on : la RLS des tables sources s''applique à l''appelant.';


-- ----------------------------------------------------------------------------
-- Contrôle — à relire après exécution. Attendu :
--   mode = invoker, exposee_a = (vide)
-- ----------------------------------------------------------------------------
SELECT c.relname AS vue,
       CASE WHEN EXISTS (SELECT 1 FROM unnest(COALESCE(c.reloptions, '{}')) o
                          -- PostgreSQL conserve l'écriture utilisée : « on »
                          -- comme « true ». Les deux valent activé.
                          WHERE o IN ('security_invoker=on', 'security_invoker=true'))
            THEN 'invoker' ELSE 'DEFINER' END AS mode,
       COALESCE((SELECT string_agg(DISTINCT g.grantee, ',' ORDER BY g.grantee)
                   FROM information_schema.role_table_grants g
                  WHERE g.table_name = c.relname
                    AND g.grantee IN ('anon', 'authenticated')), '') AS exposee_a
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname = 'public' AND c.relname = 'tonji_transactions_unified';


-- ============================================================================
-- FIN 038_tonji_vue_unifiee_rls.sql
-- ============================================================================
