-- ============================================================================
-- 019_tonji_frais_retrait.sql
-- Frais de retrait CONFIGURABLES, en matrice (type de cotisation × type de user).
--
-- On avait retiré les frais de retrait (~3 %) du cotisant (018/amendement RÈGLE 3).
-- On les rend maintenant CONFIGURABLES par croisement :
--     type de cotisation : cagnotte | tontine
--     type de user       : particulier | association
-- → un TAUX (décimal, ex : 0.03 = 3 %) par cellule. Défaut 0 (= état actuel,
--   aucun frais de retrait facturé au cotisant).
--
-- Appliqué au calcul : montant_brut = ceil( net × (1 + frais_retrait) × (1 + commission) ).
-- Stocké en JSON sur la config projet (tonji_project_config).
--
-- ⚠️ PROD (`tonji_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

ALTER TABLE public.tonji_project_config
  ADD COLUMN IF NOT EXISTS frais_retrait json NOT NULL
  DEFAULT '{"cagnotte":{"particulier":0,"association":0},"tontine":{"particulier":0,"association":0}}'::json;

-- ============================================================================
-- FIN 019_tonji_frais_retrait.sql
-- ============================================================================
