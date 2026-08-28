-- ============================================================================
-- 012_tonji_associations.sql
-- Socle « Associations » pour Tonji.
--
-- Ajoute :
--   1. users.type_compte            → NULL (pas encore choisi) | 'particulier' | 'association'
--   2. tonji_organisations          → l'association (nom, description, statut dossier)
--   3. tonji_organisation_documents → les pièces déposées (5 types)
--
-- ⚠️ PRÉFIXE : script écrit pour la PROD (préfixe `tonji_`).
--    En DEV (projet Supabase itgjlhaalodlgwsyrjnz), remplacer partout
--    `tonji_` par `tondo_`. La table `users` est PARTAGÉE (sans préfixe) :
--    l'ALTER de la section 1 est identique dans les deux environnements.
--
-- Style repris de 002_tondo.sql / tonji_signalements :
--   PK uuid gen_random_uuid(), project_id -> projects(id), trigger updated_at,
--   RLS + policy current_project_id(), grants authenticated (+ service_role).
--
-- IDEMPOTENT : ré-exécutable sans erreur.
-- À jouer manuellement dans le SQL Editor Supabase (la prod ne passe PAS par
-- `artisan migrate`).
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────
-- 1. Colonne `type_compte` sur la table partagée `users`
--    (distincte de `type_client` = grade KYC, et de `compte_type` = full/light)
--
--    NULLABLE, SANS DÉFAUT : les comptes existants restent à NULL (« pas encore
--    choisi ») et seront forcés de trancher particulier/association à leur
--    prochaine connexion sur la nouvelle version de l'app. Les nouveaux comptes
--    naissent aussi à NULL et choisissent via l'écran d'aiguillage.
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS type_compte varchar(20);

-- Contrainte de domaine (garde d'idempotence par nom + table).
-- NULL est autorisé (une valeur NULL ne fait pas échouer un CHECK) → il
-- représente l'état « pas encore choisi ».
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conname = 'users_type_compte_check'
      AND conrelid = 'public.users'::regclass
  ) THEN
    ALTER TABLE public.users
      ADD CONSTRAINT users_type_compte_check
      CHECK (type_compte IN ('particulier', 'association'));
  END IF;
END $$;


-- ─────────────────────────────────────────────────────────────────────────
-- 2. Table des organisations (associations)
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.tonji_organisations (
    id             uuid        DEFAULT gen_random_uuid() NOT NULL,
    project_id     uuid        NOT NULL,
    user_id        uuid        NOT NULL,          -- compte représentant (créateur du dossier)
    nom            text        NOT NULL,          -- Nom de l'association
    description    text,                          -- « Parlez-nous un peu de vous »
    statut         varchar(20) NOT NULL DEFAULT 'en_attente',  -- en_attente|approuve|rejete|suspendu
    motif_rejet    text,                          -- raison affichée au user si rejeté/suspendu
    plafond_fcfa   bigint      NOT NULL DEFAULT 10000000,      -- plafond cumulé/cagnotte (ajustable admin)
    numero_retrait text,                          -- Mobile Money de reversement de l'asso (optionnel)
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);

-- Clé primaire
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conname = 'tonji_organisations_pkey'
      AND conrelid = 'public.tonji_organisations'::regclass
  ) THEN
    ALTER TABLE ONLY public.tonji_organisations
      ADD CONSTRAINT tonji_organisations_pkey PRIMARY KEY (id);
  END IF;
END $$;

-- Clés étrangères (projet + compte représentant)
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tonji_organisations_project_fk'
      AND conrelid = 'public.tonji_organisations'::regclass
  ) THEN
    ALTER TABLE ONLY public.tonji_organisations
      ADD CONSTRAINT tonji_organisations_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tonji_organisations_user_fk'
      AND conrelid = 'public.tonji_organisations'::regclass
  ) THEN
    ALTER TABLE ONLY public.tonji_organisations
      ADD CONSTRAINT tonji_organisations_user_fk
      FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;
  END IF;
END $$;

-- Contrainte de domaine sur `statut`
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tonji_organisations_statut_check'
      AND conrelid = 'public.tonji_organisations'::regclass
  ) THEN
    ALTER TABLE public.tonji_organisations
      ADD CONSTRAINT tonji_organisations_statut_check
      CHECK (statut IN ('en_attente', 'approuve', 'rejete', 'suspendu'));
  END IF;
END $$;

-- Un seul dossier association par compte (v1)
CREATE UNIQUE INDEX IF NOT EXISTS tonji_organisations_project_user_idx
  ON public.tonji_organisations (project_id, user_id);
-- Recherche admin par statut
CREATE INDEX IF NOT EXISTS tonji_organisations_statut_idx
  ON public.tonji_organisations (project_id, statut);

-- Maintien automatique de updated_at
DROP TRIGGER IF EXISTS trg_tonji_organisations_updated_at ON public.tonji_organisations;
CREATE TRIGGER trg_tonji_organisations_updated_at
  BEFORE UPDATE ON public.tonji_organisations
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- RLS + policy de cloisonnement projet (accès PostgREST ; Laravel = owner, bypass)
ALTER TABLE public.tonji_organisations ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tonji_organisations_all_same_project ON public.tonji_organisations;
CREATE POLICY tonji_organisations_all_same_project ON public.tonji_organisations
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

-- Droits
GRANT SELECT, INSERT, UPDATE, DELETE ON public.tonji_organisations TO authenticated;
GRANT ALL ON public.tonji_organisations TO service_role;


-- ─────────────────────────────────────────────────────────────────────────
-- 3. Table des documents d'organisation (les 5 pièces requises)
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.tonji_organisation_documents (
    id              uuid        DEFAULT gen_random_uuid() NOT NULL,
    project_id      uuid        NOT NULL,
    organisation_id uuid        NOT NULL,
    type_piece      varchar(40) NOT NULL,   -- recepisse|statuts|pv_designation|piece_identite|autorisation_collecte
    chemin          text        NOT NULL,   -- chemin de stockage du fichier (disque privé Laravel)
    nom_fichier     text,                   -- nom d'origine du fichier
    mime            text,                   -- type MIME (application/pdf, image/jpeg…)
    taille_octets   bigint,                 -- taille du fichier
    statut          varchar(20) NOT NULL DEFAULT 'depose',   -- depose|valide|rejete
    motif_rejet     text,                   -- raison si la pièce est rejetée
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);

-- Clé primaire
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tonji_organisation_documents_pkey'
      AND conrelid = 'public.tonji_organisation_documents'::regclass
  ) THEN
    ALTER TABLE ONLY public.tonji_organisation_documents
      ADD CONSTRAINT tonji_organisation_documents_pkey PRIMARY KEY (id);
  END IF;
END $$;

-- Clés étrangères (organisation + projet)
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tonji_org_documents_org_fk'
      AND conrelid = 'public.tonji_organisation_documents'::regclass
  ) THEN
    ALTER TABLE ONLY public.tonji_organisation_documents
      ADD CONSTRAINT tonji_org_documents_org_fk
      FOREIGN KEY (organisation_id) REFERENCES public.tonji_organisations(id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tonji_org_documents_project_fk'
      AND conrelid = 'public.tonji_organisation_documents'::regclass
  ) THEN
    ALTER TABLE ONLY public.tonji_organisation_documents
      ADD CONSTRAINT tonji_org_documents_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;
  END IF;
END $$;

-- Contraintes de domaine (type de pièce + statut)
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tonji_org_documents_type_check'
      AND conrelid = 'public.tonji_organisation_documents'::regclass
  ) THEN
    ALTER TABLE public.tonji_organisation_documents
      ADD CONSTRAINT tonji_org_documents_type_check
      CHECK (type_piece IN (
        'recepisse', 'statuts', 'pv_designation', 'piece_identite', 'autorisation_collecte'
      ));
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tonji_org_documents_statut_check'
      AND conrelid = 'public.tonji_organisation_documents'::regclass
  ) THEN
    ALTER TABLE public.tonji_organisation_documents
      ADD CONSTRAINT tonji_org_documents_statut_check
      CHECK (statut IN ('depose', 'valide', 'rejete'));
  END IF;
END $$;

-- Une seule pièce par type et par organisation (un re-dépôt remplace l'ancienne)
CREATE UNIQUE INDEX IF NOT EXISTS tonji_org_documents_org_type_idx
  ON public.tonji_organisation_documents (organisation_id, type_piece);

-- Maintien automatique de updated_at
DROP TRIGGER IF EXISTS trg_tonji_org_documents_updated_at ON public.tonji_organisation_documents;
CREATE TRIGGER trg_tonji_org_documents_updated_at
  BEFORE UPDATE ON public.tonji_organisation_documents
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- RLS + policy de cloisonnement projet
ALTER TABLE public.tonji_organisation_documents ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tonji_org_documents_all_same_project ON public.tonji_organisation_documents;
CREATE POLICY tonji_org_documents_all_same_project ON public.tonji_organisation_documents
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

-- Droits
GRANT SELECT, INSERT, UPDATE, DELETE ON public.tonji_organisation_documents TO authenticated;
GRANT ALL ON public.tonji_organisation_documents TO service_role;

-- ============================================================================
-- FIN 012_tonji_associations.sql
-- ============================================================================
