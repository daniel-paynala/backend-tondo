-- ============================================================================
-- 017_migration_frais_retrait.sql   (MIGRATION DE DONNÉES — one-shot, idempotent)
--
-- Restaure les FRAIS DE RETRAIT Airtel (~3 %) qui ont été encaissés au cotisant
-- mais jamais livrés au bénéficiaire (le décaissement envoie le net tel quel) →
-- « on volait le client ». On rend ces frais au bénéficiaire via le solde.
--
-- Règle : le bénéficiaire est dû = brut / (1 + commission) = net + frais_retrait.
-- La commission Paynala reste acquise. La division par (1+commission) récupère
-- exactement le frais Airtel réellement appliqué (quel que soit le palier).
--
-- ⚠️⚠️ AVANT DE LANCER :
--   1) VÉRIFIE LA COMMISSION. Ce script suppose 2 % → diviseur 1.02.
--      Si ta commission Paynala ≠ 2 %, remplace TOUS les « 1.02 » par (1 + ta_commission).
--   2) LANCE D'ABORD LE DRY-RUN (section A, aucune écriture) pour voir l'impact.
--   3) Puis seulement la MIGRATION (section B).
--
-- IDEMPOTENT : recalcule toujours depuis payin.montant (brut) → rejouable.
-- ⚠️ PROD (`tonji_`). En DEV, remplacer par `tondo_`.
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────
-- SECTION A — DRY-RUN (SELECT, AUCUNE écriture). Impact par cagnotte.
-- ─────────────────────────────────────────────────────────────────────────
WITH du AS (
  SELECT cagnotte_id,
         SUM(CASE
               -- payin où un frais de retrait a été collecté (au-delà de la commission)
               WHEN statut = 'succes' AND montant > round(COALESCE(montant_net, 0) * 1.02) + 1
                 THEN round(montant / 1.02)                 -- dû recalculé (net + frais restauré)
               WHEN statut = 'succes'
                 THEN COALESCE(montant_net, montant)         -- inchangé (nouveau modèle)
               ELSE 0
             END) AS total_du
  FROM public.tonji_payin
  GROUP BY cagnotte_id
),
sortie AS (
  SELECT cagnotte_id, SUM(montant) AS total_payout
  FROM public.tonji_payout WHERE statut = 'succes' GROUP BY cagnotte_id
)
SELECT c.reference,
       c.titre,
       c.montant_collecte                                                       AS solde_actuel,
       GREATEST(0, COALESCE(d.total_du, 0) - COALESCE(s.total_payout, 0))        AS solde_corrige,
       GREATEST(0, COALESCE(d.total_du, 0) - COALESCE(s.total_payout, 0))
         - c.montant_collecte                                                   AS variation
FROM public.tonji_cagnottes c
LEFT JOIN du     d ON d.cagnotte_id = c.id
LEFT JOIN sortie s ON s.cagnotte_id = c.id
WHERE GREATEST(0, COALESCE(d.total_du, 0) - COALESCE(s.total_payout, 0)) <> c.montant_collecte
ORDER BY variation DESC;


-- ─────────────────────────────────────────────────────────────────────────
-- SECTION B — MIGRATION (écriture). À lancer APRÈS avoir validé le dry-run.
-- ─────────────────────────────────────────────────────────────────────────
BEGIN;

-- 1) Restaurer le dû (net + frais de retrait Airtel) sur chaque payin réussi où
--    un frais a été collecté. Les cotisations du NOUVEAU modèle (net + commission)
--    ne matchent pas la condition → non modifiées.
UPDATE public.tonji_payin
SET montant_net = round(montant / 1.02)::bigint
WHERE statut = 'succes'
  AND montant > round(COALESCE(montant_net, 0) * 1.02) + 1;

-- 2) Recalculer le solde de chaque cagnotte = Σ dû(succès) − Σ payouts(succès).
--    (Répare aussi au passage les sur-crédits / crédits manqués = réconciliation.)
UPDATE public.tonji_cagnottes c
SET montant_collecte = GREATEST(0,
      COALESCE((SELECT SUM(COALESCE(p.montant_net, p.montant))
                FROM public.tonji_payin p
                WHERE p.cagnotte_id = c.id AND p.statut = 'succes'), 0)
    - COALESCE((SELECT SUM(o.montant)
                FROM public.tonji_payout o
                WHERE o.cagnotte_id = c.id AND o.statut = 'succes'), 0)),
    updated_at = now();

COMMIT;

-- ============================================================================
-- FIN 017_migration_frais_retrait.sql
-- ============================================================================
