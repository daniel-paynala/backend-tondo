-- ============================================================================
-- 013_tonji_canal_transid.sql
-- Traçabilité du canal de cotisation + lien transactionnel sur les paiements.
--
-- Ajoute :
--   1. tonji_payin.canal            → 'app' | 'bot' | 'web' | 'ussd'
--   2. tonji_paiements.canal        → idem (pour l'historique + le dash)
--   3. tonji_paiements.trans_id     → lien vers le payin + FILET anti-doublon
--      (index UNIQUE partiel → bloque une 2e insertion pour le même trans_id)
--   4. recrée la vue tonji_transactions_unified pour exposer `canal` au dash
--
-- ⚠️ PRÉFIXE : script PROD (`tonji_`). En DEV, remplacer `tonji_` par `tondo_`.
-- IDEMPOTENT. À jouer dans le SQL Editor Supabase.
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────
-- 1. Canal sur le registre des entrées (tonji_payin) — source de vérité
--    NULLABLE (les lignes existantes restent NULL = canal inconnu).
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE public.tonji_payin
  ADD COLUMN IF NOT EXISTS canal varchar(10);

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conname = 'tonji_payin_canal_check'
      AND conrelid = 'public.tonji_payin'::regclass
  ) THEN
    ALTER TABLE public.tonji_payin
      ADD CONSTRAINT tonji_payin_canal_check
      CHECK (canal IN ('app', 'bot', 'web', 'ussd'));
  END IF;
END $$;


-- ─────────────────────────────────────────────────────────────────────────
-- 2 & 3. Canal + trans_id sur l'historique des paiements (tonji_paiements)
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE public.tonji_paiements
  ADD COLUMN IF NOT EXISTS canal varchar(10);

ALTER TABLE public.tonji_paiements
  ADD COLUMN IF NOT EXISTS trans_id text;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conname = 'tonji_paiements_canal_check'
      AND conrelid = 'public.tonji_paiements'::regclass
  ) THEN
    ALTER TABLE public.tonji_paiements
      ADD CONSTRAINT tonji_paiements_canal_check
      CHECK (canal IN ('app', 'bot', 'web', 'ussd'));
  END IF;
END $$;

-- FILET anti-doublon : un seul paiement par trans_id.
-- Index PARTIEL (WHERE trans_id IS NOT NULL) → les lignes historiques sans
-- trans_id ne sont pas contraintes ; toute future double-insertion échoue.
CREATE UNIQUE INDEX IF NOT EXISTS tonji_paiements_trans_id_uidx
  ON public.tonji_paiements (trans_id)
  WHERE trans_id IS NOT NULL;


-- ─────────────────────────────────────────────────────────────────────────
-- 4. Recrée la vue unifiée pour exposer `canal` (visible dans le dash).
--    `canal` est ajouté EN FIN de chaque SELECT (compatible CREATE OR REPLACE).
--    payout / payout_paynala = sorties système → canal NULL.
-- ─────────────────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW public.tonji_transactions_unified AS
 SELECT tonji_payin.id,
    'payin'::text AS type,
    tonji_payin.project_id,
    tonji_payin.cagnotte_id,
    tonji_payin.user_id,
    tonji_payin.trans_id,
    tonji_payin.operateur_id,
    tonji_payin.numero_tel,
    tonji_payin.montant,
    tonji_payin.statut,
    tonji_payin.request,
    tonji_payin.response,
    tonji_payin.date_creation,
    tonji_payin.created_at,
    tonji_payin.updated_at,
    tonji_payin.canal
   FROM public.tonji_payin
UNION ALL
 SELECT tonji_payout.id,
    'payout'::text AS type,
    tonji_payout.project_id,
    tonji_payout.cagnotte_id,
    tonji_payout.user_id,
    tonji_payout.trans_id,
    tonji_payout.operateur_id,
    tonji_payout.numero_tel,
    tonji_payout.montant,
    tonji_payout.statut,
    tonji_payout.request,
    tonji_payout.response,
    tonji_payout.date_creation,
    tonji_payout.created_at,
    tonji_payout.updated_at,
    NULL::varchar AS canal
   FROM public.tonji_payout
UNION ALL
 SELECT tonji_payout_paynala.id,
    'payout_paynala'::text AS type,
    tonji_payout_paynala.project_id,
    tonji_payout_paynala.cagnotte_id,
    NULL::uuid AS user_id,
    tonji_payout_paynala.trans_id,
    tonji_payout_paynala.operateur_id,
    NULL::text AS numero_tel,
    tonji_payout_paynala.montant,
    tonji_payout_paynala.statut,
    tonji_payout_paynala.request,
    tonji_payout_paynala.response,
    tonji_payout_paynala.date_creation,
    tonji_payout_paynala.created_at,
    tonji_payout_paynala.updated_at,
    NULL::varchar AS canal
   FROM public.tonji_payout_paynala;

-- ============================================================================
-- FIN 013_tonji_canal_transid.sql
-- ============================================================================
