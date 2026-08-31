-- ============================================================================
-- 018_tonji_plafonds_cagnotte.sql
-- Plafonds TOTAUX de collecte d'une cagnotte (éditables par les super_admin).
--
--   - plafond_cagnotte_particulier : 2 500 000 FCFA (cagnottes de particuliers)
--   - plafond_cagnotte_association : 10 000 000 FCFA (cagnottes d'associations,
--     loi n°35/62 ; sert de défaut, le plafond par organisation reste dans
--     tonji_organisations.plafond_fcfa)
--
-- Stockés sur la config projet (par opérateur/pays ; Tonji = airtel/GA).
-- Appliqués à la cotisation : on refuse si montant_collecte + montant dépasserait
-- le plafond du type de cagnotte.
--
-- ⚠️ PROD (`tonji_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

ALTER TABLE public.tonji_project_config
  ADD COLUMN IF NOT EXISTS plafond_cagnotte_particulier integer NOT NULL DEFAULT 2500000;

ALTER TABLE public.tonji_project_config
  ADD COLUMN IF NOT EXISTS plafond_cagnotte_association integer NOT NULL DEFAULT 10000000;

-- ============================================================================
-- FIN 018_tonji_plafonds_cagnotte.sql
-- ============================================================================
