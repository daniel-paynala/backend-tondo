-- ============================================================================
-- 016_tonji_paiements_actif.sql
-- Désactivation (soft-delete) des lignes de paiement en double.
--
-- La correction d'un écart de réconciliation « dédoublonne » les lignes
-- `tonji_paiements` en double (même trans_id) — mais sans les SUPPRIMER :
-- on les DÉSACTIVE (`actif = false`) avec un `motif_annulation` (« doublon »)
-- pour garder la traçabilité/audit.
--
-- Toutes les lignes existantes sont actives par défaut. Les lectures qui
-- doivent ignorer les doublons filtreront `actif = true`.
--
-- ⚠️ PROD (`tonji_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

ALTER TABLE public.tonji_paiements
  ADD COLUMN IF NOT EXISTS actif boolean NOT NULL DEFAULT true;

ALTER TABLE public.tonji_paiements
  ADD COLUMN IF NOT EXISTS motif_annulation text;

-- ============================================================================
-- FIN 016_tonji_paiements_actif.sql
-- ============================================================================
