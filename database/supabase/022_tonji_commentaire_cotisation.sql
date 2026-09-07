-- ============================================================================
-- 022_tonji_commentaire_cotisation.sql
-- Commentaire libre et OPTIONNEL laissé par le cotisant au moment de payer.
--
-- Usage prévu : préciser une particularité du don, ou indiquer que l'on cotise
-- pour quelqu'un d'autre (« pour ma mère », « part de Jean », …).
--
-- Deux colonnes, pas une : sur Airtel le paiement est confirmé en ASYNCHRONE.
-- La ligne `tonji_paiements` n'est créée qu'à la confirmation, à partir de la
-- ligne `tonji_payin`. Le commentaire doit donc être porté par le payin dès
-- l'initiation pour survivre au polling ET à la réconciliation
-- (`tonji:reconcilier-payins`), qui recrée les paiements depuis les payin.
--
-- Champ purement descriptif : jamais utilisé dans un calcul, ni dans une règle
-- de gestion. Visible du gérant de la cagnotte et du cotisant, jamais publié
-- sur la page publique d'une cagnotte ouverte (surface d'abus).
--
-- ⚠️ PROD (`tonji_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

ALTER TABLE public.tonji_payin
  ADD COLUMN IF NOT EXISTS commentaire text;

ALTER TABLE public.tonji_paiements
  ADD COLUMN IF NOT EXISTS commentaire text;


-- ----------------------------------------------------------------------------
-- Vue unifiée du dashboard : expose `commentaire` sur les lignes payin.
--
-- Ajouté EN FIN de chaque SELECT : CREATE OR REPLACE VIEW n'autorise que
-- l'ajout de colonnes en queue, jamais l'insertion au milieu.
-- payout / payout_paynala sont des sorties système → commentaire NULL.
-- ----------------------------------------------------------------------------
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
    tonji_payin.canal,
    tonji_payin.commentaire
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
    NULL::varchar AS canal,
    NULL::text AS commentaire
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
    NULL::varchar AS canal,
    NULL::text AS commentaire
   FROM public.tonji_payout_paynala;

GRANT SELECT ON public.tonji_transactions_unified TO authenticated;
GRANT SELECT ON public.tonji_transactions_unified TO service_role;

-- ============================================================================
-- FIN 022_tonji_commentaire_cotisation.sql
-- ============================================================================
