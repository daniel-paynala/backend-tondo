-- ============================================================================
-- 015_tonji_payin_montant_net.sql
-- Colonne `montant_net` sur tonji_payin pour une réconciliation NET vs NET.
--
-- Problème : `tonji_payin.montant` = montant BRUT (frais 2 % inclus, à la charge
-- du cotisant), alors que `cagnottes.montant_collecte` accumule le montant NET
-- (ce qui est crédité au bénéficiaire). La réconciliation soustrayait du brut à
-- partir du net → écart systématique ≈ le total des frais (faux positifs).
--
-- On ajoute une colonne `montant_net` (le net crédité) qu'on renseigne désormais
-- à la création du payin, et on la backfille depuis le JSON `request` existant.
--
-- ⚠️ PROD (`tonji_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

ALTER TABLE public.tonji_payin
  ADD COLUMN IF NOT EXISTS montant_net bigint;

-- Backfill : le net était déjà stocké dans le JSON `request.montant_net`
-- (flux Airtel app & bot). On ne touche que les lignes non encore renseignées
-- et dont la valeur est un entier valide.
UPDATE public.tonji_payin
SET montant_net = (request->>'montant_net')::bigint
WHERE montant_net IS NULL
  AND request ? 'montant_net'
  AND (request->>'montant_net') ~ '^[0-9]+$';

-- ============================================================================
-- FIN 015_tonji_payin_montant_net.sql
-- ============================================================================
