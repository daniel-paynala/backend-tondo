-- ============================================================================
-- 041_tonji_wallet.sql
-- Le solde personnel, modélisé comme une collecte d'un nouveau type.
--
-- ── Pourquoi un type de collecte, et pas une table à part ───────────────────
--
-- `tonji_payin.cagnotte_id` et `tonji_payout.cagnotte_id` sont NOT NULL. Tout
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
-- exploration publique, clôture. C'est du code, pas du schéma : voir
-- `Wallet::estWallet()` et les gardes des services concernés.
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

-- ── 1. Le type ──────────────────────────────────────────────────────────────
--
-- Le nom de l'énumération n'est PAS déduit du préfixe des tables : en
-- production les tables portent `tonji_` mais le type a pu rester `tondo_`,
-- selon l'ordre dans lequel les scripts ont été joués. On le lit donc sur la
-- colonne elle-même plutôt que de l'écrire — se tromper de nom ferait échouer
-- le script sur la première instruction.
--
-- `ADD VALUE` dans un bloc : autorisé depuis PostgreSQL 12 tant que la valeur
-- n'est pas utilisée dans la même transaction. Elle ne l'est pas ici.
DO $$
DECLARE nom_type text;
BEGIN
  SELECT t.typname
    INTO nom_type
    FROM pg_attribute a
    JOIN pg_type     t ON t.oid = a.atttypid
   WHERE a.attrelid = 'public.tonji_cagnottes'::regclass
     AND a.attname  = 'type'
     AND t.typtype  = 'e';   -- énumération seulement

  IF nom_type IS NULL THEN
    RAISE NOTICE 'Colonne type non énumérée : rien à ajouter.';
  ELSE
    EXECUTE format('ALTER TYPE public.%I ADD VALUE IF NOT EXISTS %L', nom_type, 'wallet');
  END IF;
END $$;

-- ── 2. Un seul wallet par personne ──────────────────────────────────────────
--
-- Index partiel : la contrainte ne pèse que sur les wallets, les collectes
-- ordinaires restent libres d'être nombreuses pour un même gérant.
CREATE UNIQUE INDEX IF NOT EXISTS tonji_cagnottes_wallet_unique
  ON public.tonji_cagnottes (user_id)
  WHERE type = 'wallet';

-- ── 3. La provenance d'une cotisation interne ───────────────────────────────
ALTER TABLE public.tonji_paiements
  ADD COLUMN IF NOT EXISTS cagnotte_source_id uuid
  REFERENCES public.tonji_cagnottes(id) ON DELETE SET NULL;

COMMENT ON COLUMN public.tonji_paiements.cagnotte_source_id IS
  'Collecte débitée quand la cotisation vient de l''intérieur de Tonji (wallet). NULL pour une cotisation Mobile Money.';

CREATE INDEX IF NOT EXISTS tonji_paiements_cagnotte_source_idx
  ON public.tonji_paiements (cagnotte_source_id)
  WHERE cagnotte_source_id IS NOT NULL;

-- Une collecte ne se cotise pas elle-même : ce serait un mouvement nul qui
-- gonflerait les compteurs sans déplacer un franc.
ALTER TABLE public.tonji_paiements
  DROP CONSTRAINT IF EXISTS tonji_paiements_source_differente;
ALTER TABLE public.tonji_paiements
  ADD CONSTRAINT tonji_paiements_source_differente
  CHECK (cagnotte_source_id IS NULL OR cagnotte_source_id <> cagnotte_id);
