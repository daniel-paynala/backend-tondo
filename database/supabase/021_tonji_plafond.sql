-- ============================================================================
-- 021_tonji_plafond.sql
-- Plafond de collecte PERSONNALISÉ par compte + demandes de déblocage.
--
-- Contexte : jusqu'ici le plafond total d'une cagnotte est global par type de
-- compte (particulier ~2,5 M / association 10 M, config projet). On ajoute un
-- override PAR COMPTE :
--   - association → `tonji_organisations.plafond_fcfa` (déjà existant) ;
--   - particulier → nouvelle colonne `users.plafond_personnalise` (fixée par un
--     super_admin dans le dashboard).
--
-- Déblocage au-delà de 10 M pour une association (loi n°35/62 : autorisation du
-- Conseil des ministres) : l'asso dépose un JUSTIFICATIF depuis son profil, la
-- demande est examinée dans le dashboard, et l'admin fixe le plafond accordé.
--
-- Style repris de 012 / 020 (PK uuid gen_random_uuid, project_id -> projects,
-- trigger updated_at, RLS + policy projet, grants).
--
-- ⚠️ PROD (`tonji_`). En DEV (itgjlhaalodlgwsyrjnz), remplacer par `tondo_`.
--    IDEMPOTENT. À jouer dans le SQL Editor Supabase.
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────
-- 1. Override de plafond pour un PARTICULIER (table users partagée)
--    NULL = pas d'override → on retombe sur le plafond global du type de compte.
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS plafond_personnalise bigint;


-- ─────────────────────────────────────────────────────────────────────────
-- 2. Demandes de déblocage de plafond (self-service, associations)
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.tonji_plafond_demandes (
    id                   uuid        DEFAULT gen_random_uuid() NOT NULL,
    project_id           uuid        NOT NULL,
    user_id              uuid        NOT NULL,          -- demandeur (représentant asso)
    montant_demande      bigint,                        -- montant souhaité (indicatif)
    justificatif_chemin  text        NOT NULL,          -- chemin bucket Supabase
    justificatif_nom     text,                          -- nom d'origine du fichier
    justificatif_mime    text,                          -- type MIME
    justificatif_taille  bigint,                        -- taille en octets
    statut               varchar(20) NOT NULL DEFAULT 'en_attente',  -- en_attente|approuve|rejete
    motif                text,                          -- raison si rejetée
    plafond_accorde      bigint,                        -- plafond fixé par l'admin si approuvée
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now()
);

-- Clé primaire
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conname = 'tonji_plafond_demandes_pkey'
      AND conrelid = 'public.tonji_plafond_demandes'::regclass
  ) THEN
    ALTER TABLE ONLY public.tonji_plafond_demandes
      ADD CONSTRAINT tonji_plafond_demandes_pkey PRIMARY KEY (id);
  END IF;
END $$;

-- Clés étrangères (projet + user)
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tonji_plafond_demandes_project_fk'
      AND conrelid = 'public.tonji_plafond_demandes'::regclass
  ) THEN
    ALTER TABLE ONLY public.tonji_plafond_demandes
      ADD CONSTRAINT tonji_plafond_demandes_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tonji_plafond_demandes_user_fk'
      AND conrelid = 'public.tonji_plafond_demandes'::regclass
  ) THEN
    ALTER TABLE ONLY public.tonji_plafond_demandes
      ADD CONSTRAINT tonji_plafond_demandes_user_fk
      FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;
  END IF;
END $$;

-- Contrainte de domaine sur le statut
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tonji_plafond_demandes_statut_check'
      AND conrelid = 'public.tonji_plafond_demandes'::regclass
  ) THEN
    ALTER TABLE public.tonji_plafond_demandes
      ADD CONSTRAINT tonji_plafond_demandes_statut_check
      CHECK (statut IN ('en_attente', 'approuve', 'rejete'));
  END IF;
END $$;

-- Une seule demande "vivante" par user : on remplace au re-dépôt (index sur user)
CREATE INDEX IF NOT EXISTS tonji_plafond_demandes_user_idx
  ON public.tonji_plafond_demandes (project_id, user_id);
-- File de modération par statut
CREATE INDEX IF NOT EXISTS tonji_plafond_demandes_statut_idx
  ON public.tonji_plafond_demandes (project_id, statut);

-- Maintien automatique de updated_at
DROP TRIGGER IF EXISTS trg_tonji_plafond_demandes_updated_at ON public.tonji_plafond_demandes;
CREATE TRIGGER trg_tonji_plafond_demandes_updated_at
  BEFORE UPDATE ON public.tonji_plafond_demandes
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- RLS + policy de cloisonnement projet
ALTER TABLE public.tonji_plafond_demandes ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tonji_plafond_demandes_all_same_project ON public.tonji_plafond_demandes;
CREATE POLICY tonji_plafond_demandes_all_same_project ON public.tonji_plafond_demandes
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

-- Droits
GRANT SELECT, INSERT, UPDATE, DELETE ON public.tonji_plafond_demandes TO authenticated;
GRANT ALL ON public.tonji_plafond_demandes TO service_role;

-- ============================================================================
-- FIN 021_tonji_plafond.sql
-- ============================================================================
