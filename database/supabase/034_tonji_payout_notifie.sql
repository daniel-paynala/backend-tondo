-- ============================================================================
-- 034_tonji_payout_notifie.sql
-- Trace de la notification d'un paiement marchand.
--
-- Quand un paiement marchand est confirmé, le marchand reçoit un SMS et un
-- e-mail. Cette colonne sert de **garde d'envoi unique**.
--
-- Pourquoi une garde : la confirmation peut être atteinte par plusieurs
-- chemins — l'appel client, et demain la régularisation d'un décaissement
-- dont l'issue était restée inconnue. Deux chemins concurrents enverraient
-- deux SMS « vous avez reçu 150 000 », et le marchand croirait à deux
-- paiements. C'est exactement le défaut qui avait produit le double-crédit
-- du 2026-08-31 : une garde d'idempotence qui n'était pas atomique.
--
-- La garde est un UPDATE conditionnel : `WHERE notifie_at IS NULL`. Postgres
-- garantit qu'un seul appelant concurrent voit une ligne affectée, et c'est
-- lui qui envoie. Pas de lecture puis écriture, donc pas de fenêtre entre
-- les deux.
--
-- Le parti pris est **au plus une fois** : si l'envoi échoue après la prise,
-- le message est perdu plutôt que dupliqué. Un marchand qui ne reçoit pas son
-- SMS a son solde Airtel et le portail ; un marchand qui le reçoit deux fois
-- croit avoir été payé deux fois. Les pertes sont visibles dans les journaux
-- et rejouables à la main.
--
-- ⚠️ PROD (`tonji_`). En TEST, jouer `TEST_034_tondo_payout_notifie.sql`.
-- IDEMPOTENT. À jouer dans le SQL Editor Supabase.
-- ============================================================================

ALTER TABLE public.tonji_payout
  ADD COLUMN IF NOT EXISTS notifie_at timestamptz;

COMMENT ON COLUMN public.tonji_payout.notifie_at IS
  'Instant où la notification du marchand a été prise en charge. Sert de garde d''envoi unique : la prise est un UPDATE conditionnel sur NULL, un seul appelant concurrent l''obtient. NULL sur une sortie non marchande, ou dont la notification n''a pas encore été tentée.';

-- Rattrapage : retrouver les paiements marchands confirmés que personne n'a
-- notifiés. L'index partiel ne couvre que ces lignes-là, qui sont rares par
-- construction — en régime normal il reste quasiment vide.
CREATE INDEX IF NOT EXISTS tonji_payout_a_notifier_idx
  ON public.tonji_payout (date_creation)
  WHERE marchand_id IS NOT NULL AND notifie_at IS NULL;


-- ============================================================================
-- FIN 034_tonji_payout_notifie.sql
-- ============================================================================
