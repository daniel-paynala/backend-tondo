-- ============================================================================
--  TEST_tondo_partie2.sql  —  PARTIE 2/3 : Participants, penalites, associations, canal
--  Schéma de la base de TEST, préfixe `tondo_`.
--
--  Généré le 2026-09-11 depuis database/supabase/*.sql,
--  avec `tonji_` (prod) réécrit en `tondo_` (test).
--
--  ⚠️ EXÉCUTER LES TROIS PARTIES DANS L'ORDRE : 1, puis 2, puis 3.
--     Le découpage existe parce qu'un fichier unique dépassait le délai
--     d'exécution de l'éditeur SQL Supabase.
--
--  ⚠️ NE PAS ÉDITER À LA MAIN : artefact. Corriger le fichier numéroté
--     d'origine, puis régénérer.
--
--  IDEMPOTENT : rejouable sans effet sur une base déjà à jour.
--
--  Fichiers de cette partie :
--    007_tondo_participants_retrait.sql
--    010_tondo_penalites_tontine.sql
--    011_tondo_certifie_majeur.sql
--    012_tonji_associations.sql
--    013_tonji_canal_transid.sql
--    014_tonji_storage_bucket.sql
--    015_tonji_payin_montant_net.sql
--    016_tonji_paiements_actif.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────
-- ▼ 007_tondo_participants_retrait.sql
-- ─────────────────────────────────────────────────────────

-- =============================================================================
-- 007_tondo_participants_retrait.sql
-- Ajout : numéro de retrait distinct + flag compte light sur tondo_participants
--
-- Contexte : un participant peut avoir un numéro d'inscription différent de son
-- numéro de retrait (ex : s'inscrit avec son 074... mais veut recevoir les fonds
-- sur son 077...). Les "comptes light" sont des invités sans compte Tondo propre,
-- ajoutés manuellement par le gérant — ils n'ont pas de numéro de retrait distinct.
--
-- Ordre d'exécution : après 006_seed_demo.sql
-- Idempotent : oui (IF NOT EXISTS / ALTER TABLE IF NOT EXISTS via DO block)
-- =============================================================================

do $$
begin

  -- numero_retrait_masque : numéro Mobile Money sur lequel le participant
  -- recevra les fonds. NULL = identique au numéro d'inscription (numeroMasque).
  if not exists (
    select 1
    from information_schema.columns
    where table_schema = 'public'
      and table_name   = 'tondo_participants'
      and column_name  = 'numero_retrait_masque'
  ) then
    alter table public.tondo_participants
      add column numero_retrait_masque text;
  end if;

  -- est_compte_light : vrai si le participant est un invité sans compte Tondo.
  -- Positionné automatiquement à true par le backend quand le numéro n'est pas
  -- trouvé dans public.users au moment de l'ajout.
  if not exists (
    select 1
    from information_schema.columns
    where table_schema = 'public'
      and table_name   = 'tondo_participants'
      and column_name  = 'est_compte_light'
  ) then
    alter table public.tondo_participants
      add column est_compte_light boolean not null default false;
  end if;

end $$;

comment on column public.tondo_participants.numero_retrait_masque is
  'Numéro Mobile Money sur lequel le participant recevra les fonds lors de son tour (tontine) '
  'ou d''un reversement (cotisation). NULL = même numéro que numero_masque.';

comment on column public.tondo_participants.est_compte_light is
  'Vrai si le participant est un invité sans compte Tondo propre (ajouté manuellement par '
  'le gérant, numéro inconnu du système au moment de l''ajout). '
  'Faux si le participant a un compte Tondo actif (user_id non null).';


-- ─────────────────────────────────────────────────────────
-- ▼ 010_tondo_penalites_tontine.sql
-- ─────────────────────────────────────────────────────────

-- =============================================================================
-- 010_tondo_penalites_tontine.sql
-- Pénalités de retard sur les tontines périodiques.
--
-- Règle produit :
--  – Actif par défaut (penalite_active = true).
--  – Le gérant peut désactiver en cochant "Pas de pénalité" à la création.
--  – La pénalité s'accumule par heure ou par jour après la deadline (20h00).
--  – La pénalité est ajoutée telle quelle au montant : pas de commission
--    Paynala sur la part pénalité (règle : frais uniquement sur montant base).
--
-- Tables impactées : tondo_cagnottes, tondo_payin
-- Idempotent : oui (DO $$ IF NOT EXISTS $$)
-- =============================================================================

do $$
begin

  -- tondo_cagnottes : configuration des pénalités sur la tontine
  if not exists (
    select 1 from information_schema.columns
    where table_schema = 'public' and table_name = 'tondo_cagnottes'
      and column_name = 'penalite_active'
  ) then
    alter table public.tondo_cagnottes
      add column penalite_active boolean not null default true;
  end if;

  if not exists (
    select 1 from information_schema.columns
    where table_schema = 'public' and table_name = 'tondo_cagnottes'
      and column_name = 'penalite_montant'
  ) then
    alter table public.tondo_cagnottes
      add column penalite_montant bigint;
  end if;

  if not exists (
    select 1 from information_schema.columns
    where table_schema = 'public' and table_name = 'tondo_cagnottes'
      and column_name = 'penalite_frequence'
  ) then
    alter table public.tondo_cagnottes
      add column penalite_frequence text
        check (penalite_frequence in ('heure', 'jour'));
  end if;

  -- tondo_payin : traçabilité de la part pénalité dans chaque paiement
  if not exists (
    select 1 from information_schema.columns
    where table_schema = 'public' and table_name = 'tondo_payin'
      and column_name = 'montant_penalite'
  ) then
    alter table public.tondo_payin
      add column montant_penalite bigint not null default 0;
  end if;

end $$;

comment on column public.tondo_cagnottes.penalite_active is
  'Vrai si une pénalité de retard est appliquée. Actif par défaut. '
  'Le gérant désactive en cochant "Pas de pénalité" à la création.';

comment on column public.tondo_cagnottes.penalite_montant is
  'Montant de la pénalité par période (FCFA). NULL si penalite_active = false.';

comment on column public.tondo_cagnottes.penalite_frequence is
  'Période de la pénalité : "heure" ou "jour". NULL si penalite_active = false.';

comment on column public.tondo_payin.montant_penalite is
  'Part de la pénalité de retard dans ce paiement (FCFA). '
  '0 si le paiement était à temps. Pas soumise aux frais Paynala/Airtel.';


-- ─────────────────────────────────────────────────────────
-- ▼ 011_tondo_certifie_majeur.sql
-- ─────────────────────────────────────────────────────────

-- Migration 011 — certification de majorité
-- Remplace la vérification d'âge par date de naissance
-- par une certification explicite à l'inscription.
--
-- date_naissance reste NOT NULL pour rétrocompatibilité ;
-- les nouveaux comptes reçoivent le placeholder '2000-01-01'.

ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS certifie_majeur boolean NOT NULL DEFAULT false;

-- Les utilisateurs existants avec un vrai profil sont déjà vérifiés.
UPDATE public.users
  SET certifie_majeur = true
  WHERE date_naissance <> '1900-01-01';


-- ─────────────────────────────────────────────────────────
-- ▼ 012_tonji_associations.sql
-- ─────────────────────────────────────────────────────────

-- ============================================================================
-- 012_tondo_associations.sql
-- Socle « Associations » pour Tonji.
--
-- Ajoute :
--   1. users.type_compte            → NULL (pas encore choisi) | 'particulier' | 'association'
--   2. tondo_organisations          → l'association (nom, description, statut dossier)
--   3. tondo_organisation_documents → les pièces déposées (5 types)
--
-- ⚠️ PRÉFIXE : script écrit pour la PROD (préfixe `tondo_`).
--    En DEV (projet Supabase itgjlhaalodlgwsyrjnz), remplacer partout
--    `tondo_` par `tondo_`. La table `users` est PARTAGÉE (sans préfixe) :
--    l'ALTER de la section 1 est identique dans les deux environnements.
--
-- Style repris de 002_tondo.sql / tondo_signalements :
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
CREATE TABLE IF NOT EXISTS public.tondo_organisations (
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
    WHERE conname = 'tondo_organisations_pkey'
      AND conrelid = 'public.tondo_organisations'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_organisations
      ADD CONSTRAINT tondo_organisations_pkey PRIMARY KEY (id);
  END IF;
END $$;

-- Clés étrangères (projet + compte représentant)
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_organisations_project_fk'
      AND conrelid = 'public.tondo_organisations'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_organisations
      ADD CONSTRAINT tondo_organisations_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_organisations_user_fk'
      AND conrelid = 'public.tondo_organisations'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_organisations
      ADD CONSTRAINT tondo_organisations_user_fk
      FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;
  END IF;
END $$;

-- Contrainte de domaine sur `statut`
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_organisations_statut_check'
      AND conrelid = 'public.tondo_organisations'::regclass
  ) THEN
    ALTER TABLE public.tondo_organisations
      ADD CONSTRAINT tondo_organisations_statut_check
      CHECK (statut IN ('en_attente', 'approuve', 'rejete', 'suspendu'));
  END IF;
END $$;

-- Un seul dossier association par compte (v1)
CREATE UNIQUE INDEX IF NOT EXISTS tondo_organisations_project_user_idx
  ON public.tondo_organisations (project_id, user_id);
-- Recherche admin par statut
CREATE INDEX IF NOT EXISTS tondo_organisations_statut_idx
  ON public.tondo_organisations (project_id, statut);

-- Maintien automatique de updated_at
DROP TRIGGER IF EXISTS trg_tondo_organisations_updated_at ON public.tondo_organisations;
CREATE TRIGGER trg_tondo_organisations_updated_at
  BEFORE UPDATE ON public.tondo_organisations
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- RLS + policy de cloisonnement projet (accès PostgREST ; Laravel = owner, bypass)
ALTER TABLE public.tondo_organisations ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tondo_organisations_all_same_project ON public.tondo_organisations;
CREATE POLICY tondo_organisations_all_same_project ON public.tondo_organisations
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

-- Droits
GRANT SELECT, INSERT, UPDATE, DELETE ON public.tondo_organisations TO authenticated;
GRANT ALL ON public.tondo_organisations TO service_role;


-- ─────────────────────────────────────────────────────────────────────────
-- 3. Table des documents d'organisation (les 5 pièces requises)
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.tondo_organisation_documents (
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
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_organisation_documents_pkey'
      AND conrelid = 'public.tondo_organisation_documents'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_organisation_documents
      ADD CONSTRAINT tondo_organisation_documents_pkey PRIMARY KEY (id);
  END IF;
END $$;

-- Clés étrangères (organisation + projet)
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_org_documents_org_fk'
      AND conrelid = 'public.tondo_organisation_documents'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_organisation_documents
      ADD CONSTRAINT tondo_org_documents_org_fk
      FOREIGN KEY (organisation_id) REFERENCES public.tondo_organisations(id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_org_documents_project_fk'
      AND conrelid = 'public.tondo_organisation_documents'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_organisation_documents
      ADD CONSTRAINT tondo_org_documents_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;
  END IF;
END $$;

-- Contraintes de domaine (type de pièce + statut)
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_org_documents_type_check'
      AND conrelid = 'public.tondo_organisation_documents'::regclass
  ) THEN
    ALTER TABLE public.tondo_organisation_documents
      ADD CONSTRAINT tondo_org_documents_type_check
      CHECK (type_piece IN (
        'recepisse', 'statuts', 'pv_designation', 'piece_identite', 'autorisation_collecte'
      ));
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_org_documents_statut_check'
      AND conrelid = 'public.tondo_organisation_documents'::regclass
  ) THEN
    ALTER TABLE public.tondo_organisation_documents
      ADD CONSTRAINT tondo_org_documents_statut_check
      CHECK (statut IN ('depose', 'valide', 'rejete'));
  END IF;
END $$;

-- Une seule pièce par type et par organisation (un re-dépôt remplace l'ancienne)
CREATE UNIQUE INDEX IF NOT EXISTS tondo_org_documents_org_type_idx
  ON public.tondo_organisation_documents (organisation_id, type_piece);

-- Maintien automatique de updated_at
DROP TRIGGER IF EXISTS trg_tondo_org_documents_updated_at ON public.tondo_organisation_documents;
CREATE TRIGGER trg_tondo_org_documents_updated_at
  BEFORE UPDATE ON public.tondo_organisation_documents
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- RLS + policy de cloisonnement projet
ALTER TABLE public.tondo_organisation_documents ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tondo_org_documents_all_same_project ON public.tondo_organisation_documents;
CREATE POLICY tondo_org_documents_all_same_project ON public.tondo_organisation_documents
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

-- Droits
GRANT SELECT, INSERT, UPDATE, DELETE ON public.tondo_organisation_documents TO authenticated;
GRANT ALL ON public.tondo_organisation_documents TO service_role;

-- ============================================================================
-- FIN 012_tondo_associations.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────
-- ▼ 013_tonji_canal_transid.sql
-- ─────────────────────────────────────────────────────────

-- ============================================================================
-- 013_tondo_canal_transid.sql
-- Traçabilité du canal de cotisation + lien transactionnel sur les paiements.
--
-- Ajoute :
--   1. tondo_payin.canal            → 'app' | 'bot' | 'web' | 'ussd'
--   2. tondo_paiements.canal        → idem (pour l'historique + le dash)
--   3. tondo_paiements.trans_id     → lien vers le payin + FILET anti-doublon
--      (index UNIQUE partiel → bloque une 2e insertion pour le même trans_id)
--   4. recrée la vue tondo_transactions_unified pour exposer `canal` au dash
--
-- ⚠️ PRÉFIXE : script PROD (`tondo_`). En DEV, remplacer `tondo_` par `tondo_`.
-- IDEMPOTENT. À jouer dans le SQL Editor Supabase.
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────
-- 1. Canal sur le registre des entrées (tondo_payin) — source de vérité
--    NULLABLE (les lignes existantes restent NULL = canal inconnu).
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE public.tondo_payin
  ADD COLUMN IF NOT EXISTS canal varchar(10);

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conname = 'tondo_payin_canal_check'
      AND conrelid = 'public.tondo_payin'::regclass
  ) THEN
    ALTER TABLE public.tondo_payin
      ADD CONSTRAINT tondo_payin_canal_check
      CHECK (canal IN ('app', 'bot', 'web', 'ussd'));
  END IF;
END $$;


-- ─────────────────────────────────────────────────────────────────────────
-- 2 & 3. Canal + trans_id sur l'historique des paiements (tondo_paiements)
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE public.tondo_paiements
  ADD COLUMN IF NOT EXISTS canal varchar(10);

ALTER TABLE public.tondo_paiements
  ADD COLUMN IF NOT EXISTS trans_id text;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conname = 'tondo_paiements_canal_check'
      AND conrelid = 'public.tondo_paiements'::regclass
  ) THEN
    ALTER TABLE public.tondo_paiements
      ADD CONSTRAINT tondo_paiements_canal_check
      CHECK (canal IN ('app', 'bot', 'web', 'ussd'));
  END IF;
END $$;

-- FILET anti-doublon : un seul paiement par trans_id.
-- Index PARTIEL (WHERE trans_id IS NOT NULL) → les lignes historiques sans
-- trans_id ne sont pas contraintes ; toute future double-insertion échoue.
CREATE UNIQUE INDEX IF NOT EXISTS tondo_paiements_trans_id_uidx
  ON public.tondo_paiements (trans_id)
  WHERE trans_id IS NOT NULL;


-- ─────────────────────────────────────────────────────────────────────────
-- 4. Recrée la vue unifiée pour exposer `canal` (visible dans le dash).
--    `canal` est ajouté EN FIN de chaque SELECT (compatible CREATE OR REPLACE).
--    payout / payout_paynala = sorties système → canal NULL.
-- ─────────────────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW public.tondo_transactions_unified AS
 SELECT tondo_payin.id,
    'payin'::text AS type,
    tondo_payin.project_id,
    tondo_payin.cagnotte_id,
    tondo_payin.user_id,
    tondo_payin.trans_id,
    tondo_payin.operateur_id,
    tondo_payin.numero_tel,
    tondo_payin.montant,
    tondo_payin.statut,
    tondo_payin.request,
    tondo_payin.response,
    tondo_payin.date_creation,
    tondo_payin.created_at,
    tondo_payin.updated_at,
    tondo_payin.canal
   FROM public.tondo_payin
UNION ALL
 SELECT tondo_payout.id,
    'payout'::text AS type,
    tondo_payout.project_id,
    tondo_payout.cagnotte_id,
    tondo_payout.user_id,
    tondo_payout.trans_id,
    tondo_payout.operateur_id,
    tondo_payout.numero_tel,
    tondo_payout.montant,
    tondo_payout.statut,
    tondo_payout.request,
    tondo_payout.response,
    tondo_payout.date_creation,
    tondo_payout.created_at,
    tondo_payout.updated_at,
    NULL::varchar AS canal
   FROM public.tondo_payout
UNION ALL
 SELECT tondo_payout_paynala.id,
    'payout_paynala'::text AS type,
    tondo_payout_paynala.project_id,
    tondo_payout_paynala.cagnotte_id,
    NULL::uuid AS user_id,
    tondo_payout_paynala.trans_id,
    tondo_payout_paynala.operateur_id,
    NULL::text AS numero_tel,
    tondo_payout_paynala.montant,
    tondo_payout_paynala.statut,
    tondo_payout_paynala.request,
    tondo_payout_paynala.response,
    tondo_payout_paynala.date_creation,
    tondo_payout_paynala.created_at,
    tondo_payout_paynala.updated_at,
    NULL::varchar AS canal
   FROM public.tondo_payout_paynala;

-- ============================================================================
-- FIN 013_tondo_canal_transid.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────
-- ▼ 014_tonji_storage_bucket.sql
-- ─────────────────────────────────────────────────────────

-- ============================================================================
-- 014_tondo_storage_bucket.sql
-- Bucket de stockage des pièces des associations (Supabase Storage).
--
-- Crée un bucket PRIVÉ `tonji-documents` (public=false) : les objets ne sont
-- accessibles que via des URLs signées temporaires. Le backend Laravel et le
-- dashboard utilisent la clé service_role (qui bypass la RLS Storage), donc
-- AUCUNE policy sur storage.objects n'est nécessaire pour notre usage.
--
-- Limite de taille : 8 Mo (aligné avec la validation applicative).
--
-- ⚠️ À jouer sur CHAQUE base concernée : la PROD, et la DEV si tu testes en
--    local (le nom du bucket est identique). IDEMPOTENT.
-- ============================================================================

INSERT INTO storage.buckets (id, name, public, file_size_limit)
VALUES ('tonji-documents', 'tonji-documents', false, 8388608)  -- 8 Mo
ON CONFLICT (id) DO NOTHING;

-- ============================================================================
-- FIN 014_tondo_storage_bucket.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────
-- ▼ 015_tonji_payin_montant_net.sql
-- ─────────────────────────────────────────────────────────

-- ============================================================================
-- 015_tondo_payin_montant_net.sql
-- Colonne `montant_net` sur tondo_payin pour une réconciliation NET vs NET.
--
-- Problème : `tondo_payin.montant` = montant BRUT (frais 2 % inclus, à la charge
-- du cotisant), alors que `cagnottes.montant_collecte` accumule le montant NET
-- (ce qui est crédité au bénéficiaire). La réconciliation soustrayait du brut à
-- partir du net → écart systématique ≈ le total des frais (faux positifs).
--
-- On ajoute une colonne `montant_net` (le net crédité) qu'on renseigne désormais
-- à la création du payin, et on la backfille depuis le JSON `request` existant.
--
-- ⚠️ PROD (`tondo_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

ALTER TABLE public.tondo_payin
  ADD COLUMN IF NOT EXISTS montant_net bigint;

-- Backfill : le net était déjà stocké dans le JSON `request.montant_net`
-- (flux Airtel app & bot). On ne touche que les lignes non encore renseignées
-- et dont la valeur est un entier valide.
UPDATE public.tondo_payin
SET montant_net = (request->>'montant_net')::bigint
WHERE montant_net IS NULL
  AND request ? 'montant_net'
  AND (request->>'montant_net') ~ '^[0-9]+$';

-- ============================================================================
-- FIN 015_tondo_payin_montant_net.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────
-- ▼ 016_tonji_paiements_actif.sql
-- ─────────────────────────────────────────────────────────

-- ============================================================================
-- 016_tondo_paiements_actif.sql
-- Désactivation (soft-delete) des lignes de paiement en double.
--
-- La correction d'un écart de réconciliation « dédoublonne » les lignes
-- `tondo_paiements` en double (même trans_id) — mais sans les SUPPRIMER :
-- on les DÉSACTIVE (`actif = false`) avec un `motif_annulation` (« doublon »)
-- pour garder la traçabilité/audit.
--
-- Toutes les lignes existantes sont actives par défaut. Les lectures qui
-- doivent ignorer les doublons filtreront `actif = true`.
--
-- ⚠️ PROD (`tondo_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

ALTER TABLE public.tondo_paiements
  ADD COLUMN IF NOT EXISTS actif boolean NOT NULL DEFAULT true;

ALTER TABLE public.tondo_paiements
  ADD COLUMN IF NOT EXISTS motif_annulation text;

-- ============================================================================
-- FIN 016_tondo_paiements_actif.sql
-- ============================================================================
