-- ============================================================================
-- TEST_041b_tondo_wallet.sql
-- Le solde personnel — à jouer APRÈS TEST_041a_tondo_type_wallet.sql.
--
-- ── Pourquoi un type de collecte, et pas une table à part ───────────────────
--
-- `tondo_payin.cagnotte_id` et `tondo_payout.cagnotte_id` sont NOT NULL. Tout
-- mouvement d'argent est ancré à une collecte, par contrainte de schéma —
-- c'est précisément pour cela qu'il faut aujourd'hui créer une cagnotte
-- fictive pour régler un commerce.
--
-- Faire du wallet une collecte d'un type nouveau rend donc l'existant
-- utilisable tel quel : la recharge est un `payin`, le paiement marchand et le
-- transfert passent par `SortieArgent`, l'historique, les reçus et la
-- réconciliation suivent. Aucune colonne nullable introduite là où tout est
-- contraint.
--
-- La contrepartie est que les SERVICES de collecte doivent être explicitement
-- écartés — reversement automatique, gratuité, partage, participants,
-- exploration publique, clôture. C'est du code, pas du schéma.
--
-- ── Le transfert interne ────────────────────────────────────────────────────
--
-- Un transfert Tonji → Tonji est **une cotisation faite par une collecte**. Il
-- ne crée NI payin NI payout : rien n'entre, rien ne sort. Un débit et un
-- crédit dans la même transaction, et une ligne de `paiements` côté receveur
-- qui dit d'où vient l'argent.
--
--   cagnotte_source_id   la collecte débitée, quand la cotisation vient de
--                        l'intérieur. NULL pour une cotisation Mobile Money.
--
-- C'est ce qui préserve l'invariant : la somme des soldes ne change pas, donc
-- elle continue d'égaler ce que Paynala détient pour nous.
--
-- Idempotent : rejouable sans effet de bord.
-- ============================================================================

-- ── Un seul wallet par personne ─────────────────────────────────────────────
--
-- Index partiel : la contrainte ne pèse que sur les wallets, les collectes
-- ordinaires restent libres d'être nombreuses pour un même gérant.
CREATE UNIQUE INDEX IF NOT EXISTS tondo_cagnottes_wallet_unique
  ON public.tondo_cagnottes (user_id)
  WHERE type = 'wallet';

-- ── La provenance d'une cotisation interne ──────────────────────────────────
ALTER TABLE public.tondo_paiements
  ADD COLUMN IF NOT EXISTS cagnotte_source_id uuid
  REFERENCES public.tondo_cagnottes(id) ON DELETE SET NULL;

COMMENT ON COLUMN public.tondo_paiements.cagnotte_source_id IS
  'Collecte débitée quand la cotisation vient de l''intérieur de Tonji (wallet). NULL pour une cotisation Mobile Money.';

CREATE INDEX IF NOT EXISTS tondo_paiements_cagnotte_source_idx
  ON public.tondo_paiements (cagnotte_source_id)
  WHERE cagnotte_source_id IS NOT NULL;

-- Une collecte ne se cotise pas elle-même : ce serait un mouvement nul qui
-- gonflerait les compteurs sans déplacer un franc.
ALTER TABLE public.tondo_paiements
  DROP CONSTRAINT IF EXISTS tondo_paiements_source_differente;
ALTER TABLE public.tondo_paiements
  ADD CONSTRAINT tondo_paiements_source_differente
  CHECK (cagnotte_source_id IS NULL OR cagnotte_source_id <> cagnotte_id);
