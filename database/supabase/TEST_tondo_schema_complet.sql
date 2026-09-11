-- ============================================================================
--  TEST_tondo_schema_complet.sql
--  Schéma COMPLET de la base de TEST, préfixe `tondo_`.
--
--  Généré le 2026-09-11 par concaténation des fichiers
--  database/supabase/*.sql, avec `tonji_` (prod) réécrit en `tondo_` (test).
--
--  ⚠️ NE PAS ÉDITER À LA MAIN. Ce fichier est un ARTEFACT : toute correction se
--  fait dans le fichier numéroté d'origine, puis on régénère.
--
--  ENTIÈREMENT IDEMPOTENT — vérifié avant génération :
--    • chaque `create policy` est précédé d'un `drop policy if exists` ;
--    • chaque `add constraint` est gardé par un test sur `pg_constraint` ;
--    • les tables et colonnes utilisent `if not exists`.
--  Le rejouer sur une base déjà à jour ne produit aucun changement.
--
--  EXCLU : 006_seed_demo.sql — données de démonstration, sans rapport avec une
--  mise à jour de schéma. À jouer séparément si tu veux repeupler la base.
--
--  Fichiers inclus, dans l'ordre :
--    001_fondation.sql
--    002_tondo.sql
--    003_tondo_transactions.sql
--    004_tondo_admins.sql
--    005_tondo_signalements_logs.sql
--    007_tondo_participants_retrait.sql
--    010_tondo_penalites_tontine.sql
--    011_tondo_certifie_majeur.sql
--    012_tonji_associations.sql
--    013_tonji_canal_transid.sql
--    014_tonji_storage_bucket.sql
--    015_tonji_payin_montant_net.sql
--    016_tonji_paiements_actif.sql
--    017_migration_frais_retrait.sql
--    018_tonji_plafonds_cagnotte.sql
--    019_tonji_frais_retrait.sql
--    020_tonji_device_tokens.sql
--    021_tonji_plafond.sql
--    022_tonji_commentaire_cotisation.sql
--    023_cgu_acceptation.sql
--    024_associations_sans_dossier.sql
--    025_backfill_type_compte.sql
--    026_tonji_evenements.sql
--    027_tonji_evenements_jour.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 001_fondation.sql
-- ─────────────────────────────────────────────────────────────────────────

-- ============================================================================
--  Tondo / Paynala — Fondation Supabase multi-projets
--  Script à exécuter UNE FOIS dans le SQL Editor Supabase, après création
--  d'une nouvelle instance, avant d'exécuter 002_tondo.sql.
--
--  Objectifs :
--  1. Registry `public.projects` qui liste les produits hébergés sur cette
--     base partagée (Tondo, futurs projets…).
--  2. Profil utilisateur étendu `public.users` qui ajoute `project_id` et
--     les champs métier (nom, prénom, numéro, etc.) au-dessus de auth.users.
--  3. Auth par phone OTP : trigger qui synchronise auth.users → public.users
--     en s'appuyant sur le metadata `project_slug` passé au signUp.
--  4. Helper SQL `current_project_id()` pour les policies RLS.
--  5. RLS activé sur `projects` + `users` — chaque user ne voit QUE les
--     données du projet auquel il appartient.
--
--  Idempotent : peut être ré-exécuté sans casser l'état (CREATE IF NOT EXISTS,
--  CREATE OR REPLACE, etc.).
-- ============================================================================

-- ----------------------------------------------------------------------------
--  1. Registry des projets
-- ----------------------------------------------------------------------------
create table if not exists public.projects (
  id         uuid primary key default gen_random_uuid(),
  slug       text unique not null,    -- identifiant URL-safe (ex: 'tondo')
  nom        text not null,           -- nom d'affichage (ex: 'Tondo')
  prefixe    text unique not null,    -- préfixe des tables métier (ex: 'tondo_')
  created_at timestamptz default now()
);

comment on table public.projects is
  'Registry des produits hébergés sur cette base. Chaque projet a un slug et un préfixe qui scopent ses tables métier.';

-- Insertion initiale du projet Tondo.
insert into public.projects (slug, nom, prefixe)
values ('tondo', 'Tondo', 'tondo_')
on conflict (slug) do nothing;

-- ----------------------------------------------------------------------------
--  2. Types métier partagés
-- ----------------------------------------------------------------------------
do $$
begin
  if not exists (select 1 from pg_type where typname = 'type_client') then
    create type public.type_client as enum ('particulier', 'entreprise', 'marchand');
  end if;
  if not exists (select 1 from pg_type where typname = 'sexe') then
    create type public.sexe as enum ('homme', 'femme');
  end if;
end $$;

-- ----------------------------------------------------------------------------
--  3. Profil utilisateur étendu (au-dessus de auth.users)
-- ----------------------------------------------------------------------------
create table if not exists public.users (
  id              uuid primary key references auth.users(id) on delete cascade,
  project_id      uuid not null references public.projects(id) on delete restrict,

  -- Champs collectés au sign-up (RÈGLE 4-bis : sign-up minimal)
  nom             text not null,
  prenom          text not null,
  date_naissance  date not null,
  numero          text not null,
  type_client     public.type_client not null default 'particulier',
  kyc_valide      boolean not null default false,

  -- Champs différés (collectés plus tard, juste avant le service qui en a besoin)
  sexe            public.sexe,
  adresse         text,
  email           text,

  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now()
);

comment on table public.users is
  'Profil utilisateur étendu. id = auth.users.id (FK). project_id scope l''appartenance.';

-- Un numéro ne peut être utilisé qu'une fois par projet (deux personnes peuvent
-- partager le numéro entre projets différents, mais pas dans le même).
create unique index if not exists users_project_numero_idx
  on public.users (project_id, numero);

-- ----------------------------------------------------------------------------
--  4. Helper : récupère le project_id du user authentifié
-- ----------------------------------------------------------------------------
-- Utilisé par les policies RLS de toutes les tables métier (tondo_*).
-- `security definer` pour que la function puisse lire public.users sans
-- que l'utilisateur appelant ait besoin de droits explicites.
create or replace function public.current_project_id()
returns uuid
language sql
stable
security definer
set search_path = public
as $$
  select project_id
  from public.users
  where id = auth.uid()
$$;

comment on function public.current_project_id() is
  'Retourne le project_id du user actuellement authentifié (via auth.uid()).';

-- ----------------------------------------------------------------------------
--  5. Trigger : à chaque création dans auth.users, créer la row public.users
-- ----------------------------------------------------------------------------
-- Le metadata `project_slug` DOIT être passé au signUp côté client :
--   supabase.auth.signUp({
--     phone: '+241...',
--     options: { data: { project_slug: 'tondo', nom: '...', prenom: '...', ... }}
--   });
create or replace function public.handle_new_auth_user()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  v_project_id uuid;
  v_slug       text;
begin
  v_slug := coalesce(new.raw_user_meta_data->>'project_slug', 'tondo');

  select id into v_project_id from public.projects where slug = v_slug;
  if v_project_id is null then
    raise exception 'Project slug not found in registry: %', v_slug;
  end if;

  insert into public.users (
    id,
    project_id,
    nom,
    prenom,
    date_naissance,
    numero,
    type_client
  ) values (
    new.id,
    v_project_id,
    coalesce(new.raw_user_meta_data->>'nom', ''),
    coalesce(new.raw_user_meta_data->>'prenom', ''),
    coalesce((new.raw_user_meta_data->>'date_naissance')::date, current_date),
    coalesce(new.phone, ''),
    coalesce(
      (new.raw_user_meta_data->>'type_client')::public.type_client,
      'particulier'
    )
  );

  return new;
end;
$$;

drop trigger if exists on_auth_user_created on auth.users;
create trigger on_auth_user_created
  after insert on auth.users
  for each row execute function public.handle_new_auth_user();

-- ----------------------------------------------------------------------------
--  6. Trigger updated_at générique
-- ----------------------------------------------------------------------------
create or replace function public.set_updated_at()
returns trigger
language plpgsql
as $$
begin
  new.updated_at = now();
  return new;
end;
$$;

drop trigger if exists trg_users_updated_at on public.users;
create trigger trg_users_updated_at
  before update on public.users
  for each row execute function public.set_updated_at();

-- ----------------------------------------------------------------------------
--  7. Row Level Security
-- ----------------------------------------------------------------------------
alter table public.projects enable row level security;
alter table public.users    enable row level security;

-- projects : un user ne voit que son propre projet (read-only depuis l'app).
drop policy if exists "projects_select_own" on public.projects;
create policy "projects_select_own" on public.projects
  for select
  using (id = public.current_project_id());

-- users : on lit uniquement les users du même projet.
drop policy if exists "users_select_same_project" on public.users;
create policy "users_select_same_project" on public.users
  for select
  using (project_id = public.current_project_id());

-- users : on n'update que sa propre row (changer son adresse, etc.).
drop policy if exists "users_update_self" on public.users;
create policy "users_update_self" on public.users
  for update
  using (id = auth.uid())
  with check (id = auth.uid() and project_id = public.current_project_id());

-- Pas de policy INSERT : on passe par le trigger on_auth_user_created.
-- Pas de policy DELETE côté app : géré par la cascade depuis auth.users.

-- ----------------------------------------------------------------------------
--  8. Permissions PostgREST (Supabase auto-exposes via API)
-- ----------------------------------------------------------------------------
grant usage on schema public to anon, authenticated;
grant select on public.projects to authenticated;
grant select, update on public.users to authenticated;

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 002_tondo.sql
-- ─────────────────────────────────────────────────────────────────────────

-- ============================================================================
--  Tondo — Tables métier (préfixées `tondo_`)
--  À exécuter dans le SQL Editor Supabase APRÈS 001_fondation.sql.
--
--  Convention : toutes les tables métier de Tondo portent le préfixe `tondo_`.
--  Chaque ligne dénormalise `project_id` directement (au lieu de joindre
--  systématiquement via users) pour que les policies RLS restent O(1).
--
--  Naming : snake_case + pluriel (PG standard, choix Daniel 2026-05-11).
-- ============================================================================

-- ----------------------------------------------------------------------------
--  1. Types métier Tondo
-- ----------------------------------------------------------------------------
do $$
begin
  if not exists (select 1 from pg_type where typname = 'tondo_type_cagnotte') then
    create type public.tondo_type_cagnotte as enum (
      'tontine_periodique',
      'cagnotte_ouverte'
    );
  end if;

  if not exists (select 1 from pg_type where typname = 'tondo_statut_cagnotte') then
    create type public.tondo_statut_cagnotte as enum (
      'active',
      'cloturee'
    );
  end if;

  if not exists (select 1 from pg_type where typname = 'tondo_periodicite') then
    create type public.tondo_periodicite as enum (
      'hebdomadaire',
      'mensuelle'
    );
  end if;

  if not exists (select 1 from pg_type where typname = 'tondo_jour_semaine') then
    create type public.tondo_jour_semaine as enum (
      'lundi','mardi','mercredi','jeudi','vendredi','samedi','dimanche'
    );
  end if;

  if not exists (select 1 from pg_type where typname = 'tondo_role_utilisateur') then
    create type public.tondo_role_utilisateur as enum (
      'gerant',
      'cotiseur'
    );
  end if;

  if not exists (select 1 from pg_type where typname = 'tondo_statut_paiement') then
    create type public.tondo_statut_paiement as enum (
      'paye',
      'en_attente',
      'en_retard'
    );
  end if;
end $$;

-- ----------------------------------------------------------------------------
--  2. tondo_cagnottes — la table principale (tontines + cotisations)
-- ----------------------------------------------------------------------------
--  PK = UUID interne (jamais montré à l'utilisateur).
--  reference = 6 chiffres unique, c'est CE QUE L'UTILISATEUR VOIT/PARTAGE
--  (RÈGLE 4-bis : identifiant cagnotte numérique court, saisi en USSD).
-- ----------------------------------------------------------------------------
create table if not exists public.tondo_cagnottes (
  id                     uuid primary key default gen_random_uuid(),
  reference              text not null unique
                         check (reference ~ '^\d{6}$'),  -- 6 chiffres exacts
  project_id             uuid not null references public.projects(id) on delete restrict,
  user_id                uuid not null references public.users(id) on delete restrict,

  titre                  text not null,
  type                   public.tondo_type_cagnotte not null,
  statut                 public.tondo_statut_cagnotte not null default 'active',

  -- Compteurs et montants
  montant_collecte       bigint not null default 0,    -- cumul des paiements reçus à ce jour
  montant_beneficiaire   bigint,                       -- net que le bénéficiaire doit recevoir
  montant_avec_frais     bigint,                       -- brut que le cotisant paie (net + frais 2 % + frais opérateur)
  total_a_envoyer        bigint,                       -- somme brute des envois Airtel (cash + frais retrait), avant commission Paynala
  nombre_participants    int not null default 0,        -- nombre déclaré à la création (cible tontine)
  nombre_inscrits        int not null default 0,        -- participants effectivement inscrits (hors créateur)
  nombre_splits          int,                          -- nombre de parts / groupes (tontine)
  nombre_envois          int,                          -- nombre d'envois prévus au bénéficiaire (tontine)
  numero_retrait_masque  text,                         -- immutable après création (RÈGLE non négociable)

  -- Cagnotte ouverte (cotisation) — optionnels
  montant_cible          bigint,
  date_fin               timestamptz,

  -- Tontine périodique — optionnels
  montant_par_cycle      bigint,
  periodicite            public.tondo_periodicite,
  intervalle             int not null default 1,
  jour_semaine           public.tondo_jour_semaine,
  jour_mois              int check (jour_mois between 1 and 28),
  date_demarrage         timestamptz,                  -- quand la tontine a officiellement démarré (statut → en_cours)

  date_creation          timestamptz not null default now(),
  created_at             timestamptz not null default now(),
  updated_at             timestamptz not null default now()
);

comment on table public.tondo_cagnottes is
  'Cagnotte Tondo : tontine périodique OU cotisation ouverte. user_id = gérant créateur.';
comment on column public.tondo_cagnottes.id is
  'UUID interne. Jamais exposé à l''utilisateur. Utilisé pour les FK et les routes API.';
comment on column public.tondo_cagnottes.reference is
  'Identifiant public 4-5 chiffres. C''est CE QUE L''UTILISATEUR VOIT, SAISIT EN USSD ET PARTAGE.';
comment on column public.tondo_cagnottes.montant_beneficiaire is
  'Montant net que le bénéficiaire recevra. Pour tontine : montant_par_cycle × nombre_participants. Pour cotisation : montant_cible.';
comment on column public.tondo_cagnottes.montant_avec_frais is
  'Montant brut payé par le cotisant = montant_beneficiaire + frais Paynala (2 %) + frais opérateur.';
comment on column public.tondo_cagnottes.nombre_splits is
  'Découpage technique du montant_beneficiaire dicté par le plafond Mobile Money (500 000 FCFA par transaction, RÈGLE 4-bis). Si montant_beneficiaire <= 500k, splits = 1. Sinon splits = ceil(montant_beneficiaire / 500k). Ex : 600k → 2 splits, 3M → 6 splits.';
comment on column public.tondo_cagnottes.nombre_envois is
  'Nombre d''envois Mobile Money réels effectués au bénéficiaire. Égal à nombre_splits dans le cas idéal, MAIS peut être supérieur si les frais opérateur (3 % retrait) prélevés sur chaque envoi nécessitent un envoi supplémentaire de régularisation pour que le bénéficiaire reçoive exactement le montant_beneficiaire annoncé. Ex : 3M → 6 splits + 1 envoi correction = 7 envois.';

create index if not exists tondo_cagnottes_user_id_idx       on public.tondo_cagnottes (user_id);
create index if not exists tondo_cagnottes_project_id_idx    on public.tondo_cagnottes (project_id);
create index if not exists tondo_cagnottes_statut_type_idx   on public.tondo_cagnottes (statut, type);
create index if not exists tondo_cagnottes_reference_idx     on public.tondo_cagnottes (reference);
-- Note : reference est déjà UNIQUE, l'index ci-dessus est redondant côté
-- contrainte mais accélère les lookups par référence (USSD, partage de lien).

drop trigger if exists trg_tondo_cagnottes_updated_at on public.tondo_cagnottes;
create trigger trg_tondo_cagnottes_updated_at
  before update on public.tondo_cagnottes
  for each row execute function public.set_updated_at();

-- ----------------------------------------------------------------------------
--  3. tondo_participants — invités à une cagnotte
-- ----------------------------------------------------------------------------
create table if not exists public.tondo_participants (
  id                     uuid primary key default gen_random_uuid(),
  project_id             uuid not null references public.projects(id) on delete restrict,
  cagnotte_id            uuid not null references public.tondo_cagnottes(id) on delete cascade,
  user_id                uuid references public.users(id) on delete set null,  -- nullable : participant non encore inscrit

  nom                    text not null,
  prenom                 text not null,
  numero_masque          text not null,                -- numéro d'inscription (Mobile Money de contact)
  numero_retrait_masque  text,                         -- numéro de réception des fonds, NULL = identique à numero_masque
  est_compte_light       boolean not null default false, -- true = invité sans compte Tondo (ajouté manuellement par le gérant)
  statut_paiement        public.tondo_statut_paiement not null default 'en_attente',
  ordre_passage          smallint not null default 0,  -- ordre de réception (tontine) ; 0 = non défini
  montant_paye           bigint not null default 0,
  date_dernier_paiement  timestamptz,

  created_at             timestamptz not null default now()
);

create index if not exists tondo_participants_cagnotte_id_idx on public.tondo_participants (cagnotte_id);
create index if not exists tondo_participants_user_id_idx     on public.tondo_participants (user_id);
create index if not exists tondo_participants_project_id_idx  on public.tondo_participants (project_id);

-- Un user ne peut apparaître qu'une fois par cagnotte (s'il est lié à un compte).
create unique index if not exists tondo_participants_cagnotte_user_uniq
  on public.tondo_participants (cagnotte_id, user_id)
  where user_id is not null;

-- ----------------------------------------------------------------------------
--  4. tondo_paiements — historique des contributions
-- ----------------------------------------------------------------------------
create table if not exists public.tondo_paiements (
  id              uuid primary key default gen_random_uuid(),
  project_id      uuid not null references public.projects(id) on delete restrict,
  cagnotte_id     uuid not null references public.tondo_cagnottes(id) on delete cascade,
  participant_id  uuid not null references public.tondo_participants(id) on delete cascade,
  user_id         uuid references public.users(id) on delete set null,  -- celui qui a payé

  montant         bigint not null check (montant > 0),
  date            timestamptz not null default now(),

  created_at      timestamptz not null default now()
);

create index if not exists tondo_paiements_cagnotte_id_idx    on public.tondo_paiements (cagnotte_id);
create index if not exists tondo_paiements_participant_id_idx on public.tondo_paiements (participant_id);
create index if not exists tondo_paiements_user_id_idx        on public.tondo_paiements (user_id);
create index if not exists tondo_paiements_date_idx           on public.tondo_paiements (date desc);

-- ----------------------------------------------------------------------------
--  5. RLS — scoping strict par project_id
-- ----------------------------------------------------------------------------
-- Toutes les tables tondo_* utilisent le même pattern :
-- - USING  (lecture) : project_id = current_project_id()
-- - WITH CHECK (écriture) : idem → impossible d'insérer dans un autre projet

alter table public.tondo_cagnottes    enable row level security;
alter table public.tondo_participants enable row level security;
alter table public.tondo_paiements    enable row level security;

-- tondo_cagnottes
drop policy if exists "tondo_cagnottes_all_same_project" on public.tondo_cagnottes;
create policy "tondo_cagnottes_all_same_project" on public.tondo_cagnottes
  for all
  using (project_id = public.current_project_id())
  with check (project_id = public.current_project_id());

-- tondo_participants
drop policy if exists "tondo_participants_all_same_project" on public.tondo_participants;
create policy "tondo_participants_all_same_project" on public.tondo_participants
  for all
  using (project_id = public.current_project_id())
  with check (project_id = public.current_project_id());

-- tondo_paiements
drop policy if exists "tondo_paiements_all_same_project" on public.tondo_paiements;
create policy "tondo_paiements_all_same_project" on public.tondo_paiements
  for all
  using (project_id = public.current_project_id())
  with check (project_id = public.current_project_id());

-- ----------------------------------------------------------------------------
--  6. Permissions PostgREST
-- ----------------------------------------------------------------------------
grant select, insert, update, delete on public.tondo_cagnottes    to authenticated;
grant select, insert, update, delete on public.tondo_participants to authenticated;
grant select, insert, update, delete on public.tondo_paiements    to authenticated;

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 003_tondo_transactions.sql
-- ─────────────────────────────────────────────────────────────────────────

-- ============================================================================
--  Tondo — Tables transactionnelles pour la réconciliation financière
--  À exécuter dans le SQL Editor Supabase APRÈS 002_tondo.sql.
--
--  4 tables pour tracer chaque mouvement d'argent et permettre la
--  réconciliation entre Paynala, les opérateurs Mobile Money et les
--  utilisateurs Tondo :
--
--  1. tondo_payin           — argent entrant (cotisations des participants
--                             vers la cagnotte Tondo).
--  2. tondo_payout          — argent sortant vers le BÉNÉFICIAIRE de la
--                             cagnotte (l'utilisateur final qui touche).
--  3. tondo_payout_paynala  — argent sortant vers PAYNALA (les frais de
--                             commission 2 % que Paynala encaisse).
--  4. tondo_retry           — historique des tentatives quand un payin /
--                             payout / payout_paynala échoue et qu'on
--                             relance.
--
--  Conventions communes :
--   - PK uuid interne. trans_id = identifiant Tondo human-readable.
--   - operateur_id = identifiant de la transaction côté opérateur Mobile
--     Money (Airtel Money, Moov Money, agrégateur tiers — peu importe
--     tant qu'il est unique chez eux).
--   - request / response stockés en jsonb pour rester queryable côté SQL.
--   - cagnotte_id pointe vers tondo_cagnottes (uuid).
--   - project_id sur chaque ligne pour les policies RLS O(1).
-- ============================================================================

-- ----------------------------------------------------------------------------
--  1. Type statut commun à toutes les transactions
-- ----------------------------------------------------------------------------
do $$
begin
  if not exists (select 1 from pg_type where typname = 'tondo_statut_transaction') then
    create type public.tondo_statut_transaction as enum (
      'initie',     -- créée côté Tondo, pas encore envoyée à l'opérateur
      'en_cours',   -- envoyée à l'opérateur, en attente de réponse
      'succes',     -- opérateur a confirmé OK
      'echec',      -- opérateur a renvoyé une erreur (ou timeout)
      'annule'      -- annulée côté Tondo (ex : retour utilisateur, expiration)
    );
  end if;
end $$;

-- ----------------------------------------------------------------------------
--  2. tondo_payin — Cotisation entrante (cotiseur → cagnotte)
-- ----------------------------------------------------------------------------
create table if not exists public.tondo_payin (
  id              uuid primary key default gen_random_uuid(),
  project_id      uuid not null references public.projects(id) on delete restrict,
  cagnotte_id     uuid not null references public.tondo_cagnottes(id) on delete restrict,
  user_id         uuid references public.users(id) on delete set null,  -- nullable : pas de compte Tondo

  trans_id        text not null unique,           -- identifiant Tondo human-readable
  operateur_id    text,                            -- identifiant transaction côté opérateur Mobile Money
  numero_tel      text not null,                   -- numéro du cotisant
  montant         bigint not null check (montant > 0),
  statut          public.tondo_statut_transaction not null default 'initie',

  request         jsonb not null default '{}'::jsonb,
  response        jsonb,

  date_creation   timestamptz not null default now(),
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now()
);

comment on table public.tondo_payin is
  'Cotisation entrante. Trace chaque appel API à l''opérateur Mobile Money pour débiter le cotisant et créditer la cagnotte.';
comment on column public.tondo_payin.trans_id is 'Identifiant Tondo human-readable de la transaction (ex : TONDO-PAYIN-00012345).';
comment on column public.tondo_payin.operateur_id is 'Identifiant unique de la transaction côté opérateur (Airtel/Moov/agrégateur). Sert à la réconciliation.';
comment on column public.tondo_payin.user_id is 'Compte Tondo du cotisant si existant. Nullable car un cotiseur peut payer sans avoir de compte.';

create index if not exists tondo_payin_cagnotte_id_idx  on public.tondo_payin (cagnotte_id);
create index if not exists tondo_payin_user_id_idx      on public.tondo_payin (user_id);
create index if not exists tondo_payin_statut_idx       on public.tondo_payin (statut);
create index if not exists tondo_payin_operateur_id_idx on public.tondo_payin (operateur_id);
create index if not exists tondo_payin_date_creation_idx on public.tondo_payin (date_creation desc);

drop trigger if exists trg_tondo_payin_updated_at on public.tondo_payin;
create trigger trg_tondo_payin_updated_at
  before update on public.tondo_payin
  for each row execute function public.set_updated_at();

-- ----------------------------------------------------------------------------
--  3. tondo_payout — Décaissement vers le BÉNÉFICIAIRE (cagnotte → user final)
-- ----------------------------------------------------------------------------
create table if not exists public.tondo_payout (
  id              uuid primary key default gen_random_uuid(),
  project_id      uuid not null references public.projects(id) on delete restrict,
  cagnotte_id     uuid not null references public.tondo_cagnottes(id) on delete restrict,
  user_id         uuid references public.users(id) on delete set null,  -- nullable : bénéficiaire sans compte

  trans_id        text not null unique,
  operateur_id    text,
  numero_tel      text not null,                   -- numéro du bénéficiaire qui reçoit
  montant         bigint not null check (montant > 0),
  statut          public.tondo_statut_transaction not null default 'initie',

  request         jsonb not null default '{}'::jsonb,
  response        jsonb,

  date_creation   timestamptz not null default now(),
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now()
);

comment on table public.tondo_payout is
  'Envoi de fonds vers le bénéficiaire de la cagnotte (utilisateur final qui touche). Une cagnotte peut générer plusieurs payouts si montant_beneficiaire > 500k FCFA (cf. nombre_splits / nombre_envois sur tondo_cagnottes).';
comment on column public.tondo_payout.user_id is 'Compte Tondo du bénéficiaire si existant. Nullable car le numéro de retrait peut désigner quelqu''un sans compte Tondo.';

create index if not exists tondo_payout_cagnotte_id_idx  on public.tondo_payout (cagnotte_id);
create index if not exists tondo_payout_user_id_idx      on public.tondo_payout (user_id);
create index if not exists tondo_payout_statut_idx       on public.tondo_payout (statut);
create index if not exists tondo_payout_operateur_id_idx on public.tondo_payout (operateur_id);
create index if not exists tondo_payout_date_creation_idx on public.tondo_payout (date_creation desc);

drop trigger if exists trg_tondo_payout_updated_at on public.tondo_payout;
create trigger trg_tondo_payout_updated_at
  before update on public.tondo_payout
  for each row execute function public.set_updated_at();

-- ----------------------------------------------------------------------------
--  4. tondo_payout_paynala — Décaissement des frais 2 % vers PAYNALA
-- ----------------------------------------------------------------------------
--  Pas de numero_tel ni user_id : le destinataire est Paynala (l'entité
--  porteuse), pas un utilisateur final. Le compte Paynala bénéficiaire
--  est configuré au niveau opérateur, pas par transaction.
-- ----------------------------------------------------------------------------
create table if not exists public.tondo_payout_paynala (
  id              uuid primary key default gen_random_uuid(),
  project_id      uuid not null references public.projects(id) on delete restrict,
  cagnotte_id     uuid not null references public.tondo_cagnottes(id) on delete restrict,

  trans_id        text not null unique,
  operateur_id    text,
  montant         bigint not null check (montant > 0),
  statut          public.tondo_statut_transaction not null default 'initie',

  request         jsonb not null default '{}'::jsonb,
  response        jsonb,

  date_creation   timestamptz not null default now(),
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now()
);

comment on table public.tondo_payout_paynala is
  'Encaissement par Paynala de la commission 2 % sur chaque cotisation. Le destinataire est l''entité Paynala (configurée chez l''opérateur), pas un user final.';

create index if not exists tondo_payout_paynala_cagnotte_id_idx  on public.tondo_payout_paynala (cagnotte_id);
create index if not exists tondo_payout_paynala_statut_idx       on public.tondo_payout_paynala (statut);
create index if not exists tondo_payout_paynala_operateur_id_idx on public.tondo_payout_paynala (operateur_id);
create index if not exists tondo_payout_paynala_date_creation_idx on public.tondo_payout_paynala (date_creation desc);

drop trigger if exists trg_tondo_payout_paynala_updated_at on public.tondo_payout_paynala;
create trigger trg_tondo_payout_paynala_updated_at
  before update on public.tondo_payout_paynala
  for each row execute function public.set_updated_at();

-- ----------------------------------------------------------------------------
--  5. tondo_retry — Tentatives de retry
-- ----------------------------------------------------------------------------
--  Une row par tentative (la première inclue). Lien vers la transaction
--  parent via UNE seule FK (payin_id OU payout_id OU payout_paynala_id).
--  Audit-only : pas d'UPDATE possible côté app, juste INSERT.
-- ----------------------------------------------------------------------------
create table if not exists public.tondo_retry (
  id                 uuid primary key default gen_random_uuid(),
  project_id         uuid not null references public.projects(id) on delete restrict,

  payin_id           uuid references public.tondo_payin(id) on delete cascade,
  payout_id          uuid references public.tondo_payout(id) on delete cascade,
  payout_paynala_id  uuid references public.tondo_payout_paynala(id) on delete cascade,

  tentative          int not null check (tentative >= 1),
  request            jsonb,
  response           jsonb,
  statut             public.tondo_statut_transaction not null,
  erreur_message     text,

  date_creation      timestamptz not null default now(),

  -- Exactement une des 3 FK doit être renseignée
  constraint tondo_retry_exactly_one_fk check (
    (case when payin_id           is null then 0 else 1 end
   + case when payout_id          is null then 0 else 1 end
   + case when payout_paynala_id  is null then 0 else 1 end) = 1
  )
);

comment on table public.tondo_retry is
  'Historique des tentatives sur les transactions payin / payout / payout_paynala. Audit-only : on n''édite jamais une row, on en INSERT une nouvelle à chaque tentative.';

-- Une transaction ne peut pas avoir deux tentatives avec le même numéro
create unique index if not exists tondo_retry_payin_tentative_uniq
  on public.tondo_retry (payin_id, tentative) where payin_id is not null;
create unique index if not exists tondo_retry_payout_tentative_uniq
  on public.tondo_retry (payout_id, tentative) where payout_id is not null;
create unique index if not exists tondo_retry_payout_paynala_tentative_uniq
  on public.tondo_retry (payout_paynala_id, tentative) where payout_paynala_id is not null;

create index if not exists tondo_retry_statut_idx on public.tondo_retry (statut);
create index if not exists tondo_retry_date_creation_idx on public.tondo_retry (date_creation desc);

-- ----------------------------------------------------------------------------
--  6. RLS — scoping strict par project_id
-- ----------------------------------------------------------------------------
alter table public.tondo_payin           enable row level security;
alter table public.tondo_payout          enable row level security;
alter table public.tondo_payout_paynala  enable row level security;
alter table public.tondo_retry           enable row level security;

drop policy if exists "tondo_payin_all_same_project" on public.tondo_payin;
create policy "tondo_payin_all_same_project" on public.tondo_payin
  for all
  using (project_id = public.current_project_id())
  with check (project_id = public.current_project_id());

drop policy if exists "tondo_payout_all_same_project" on public.tondo_payout;
create policy "tondo_payout_all_same_project" on public.tondo_payout
  for all
  using (project_id = public.current_project_id())
  with check (project_id = public.current_project_id());

drop policy if exists "tondo_payout_paynala_all_same_project" on public.tondo_payout_paynala;
create policy "tondo_payout_paynala_all_same_project" on public.tondo_payout_paynala
  for all
  using (project_id = public.current_project_id())
  with check (project_id = public.current_project_id());

drop policy if exists "tondo_retry_all_same_project" on public.tondo_retry;
create policy "tondo_retry_all_same_project" on public.tondo_retry
  for all
  using (project_id = public.current_project_id())
  with check (project_id = public.current_project_id());

-- ----------------------------------------------------------------------------
--  7. Permissions PostgREST
-- ----------------------------------------------------------------------------
-- payin/payout/payout_paynala : insert + update (pour passer en succes/echec
-- une fois la réponse opérateur reçue), pas de delete (audit).
grant select, insert, update on public.tondo_payin           to authenticated;
grant select, insert, update on public.tondo_payout          to authenticated;
grant select, insert, update on public.tondo_payout_paynala  to authenticated;

-- retry : INSERT only depuis l'app (chaque tentative crée une row, jamais update)
grant select, insert on public.tondo_retry to authenticated;

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 004_tondo_admins.sql
-- ─────────────────────────────────────────────────────────────────────────

-- ============================================================================
--  Tondo — Table d'authentification des administrateurs du dashboard
--  À exécuter APRÈS 003_tondo_transactions.sql.
--
--  Les admins NE PASSENT PAS par Supabase Auth (phone OTP) — ils ont leur
--  propre table avec email + password hash bcrypt, et s'authentifient via
--  Laravel Sanctum (token-based, cookie HttpOnly côté Next.js).
--
--  RLS désactivée sur cette table : c'est Laravel qui sert d'autorité,
--  via la service_role key qui bypass RLS.
-- ============================================================================

-- ----------------------------------------------------------------------------
--  1. Type rôle admin
-- ----------------------------------------------------------------------------
do $$
begin
  if not exists (select 1 from pg_type where typname = 'tondo_role_admin') then
    create type public.tondo_role_admin as enum (
      'super_admin',
      'admin',
      'operateur',
      'lecteur'
    );
  end if;
end $$;

-- ----------------------------------------------------------------------------
--  2. Table tondo_admins
-- ----------------------------------------------------------------------------
create table if not exists public.tondo_admins (
  id              uuid primary key default gen_random_uuid(),
  project_id      uuid not null references public.projects(id) on delete restrict,

  email           text not null unique,
  password_hash   text not null,                          -- bcrypt 60 chars ($2y$...)
  nom             text not null,
  prenom          text not null,

  role            public.tondo_role_admin not null default 'admin',
  actif           boolean not null default true,

  derniere_connexion timestamptz,
  remember_token  text,                                   -- compat Laravel Auth

  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now()
);

comment on table public.tondo_admins is
  'Administrateurs du dashboard Tondo. Authentification email + bcrypt via Laravel Sanctum. Distinct de public.users (qui auth via phone OTP Supabase).';
comment on column public.tondo_admins.password_hash is 'Hash bcrypt généré par Laravel Hash::make() (cost 12). Format $2y$...';

create index if not exists tondo_admins_project_id_idx on public.tondo_admins (project_id);
create index if not exists tondo_admins_email_idx on public.tondo_admins (email);

drop trigger if exists trg_tondo_admins_updated_at on public.tondo_admins;
create trigger trg_tondo_admins_updated_at
  before update on public.tondo_admins
  for each row execute function public.set_updated_at();

-- ----------------------------------------------------------------------------
--  3. RLS désactivée
-- ----------------------------------------------------------------------------
-- Les admins ne sont jamais accédés par un client Supabase Auth — c'est
-- Laravel qui sert d'autorité avec la service_role key (bypass RLS).
alter table public.tondo_admins disable row level security;

-- ----------------------------------------------------------------------------
--  4. Seed : Daniel Doviakon (super_admin)
-- ----------------------------------------------------------------------------
insert into public.tondo_admins (
  project_id,
  email,
  password_hash,
  nom,
  prenom,
  role
)
select
  (select id from public.projects where slug = 'tondo'),
  'daniel@paynala.com',
  '$2y$12$Bnu6ZoVicSzW10N4ielmNOrlvl48eKxk0V.GJu8fV5AoQpfUF.ZuK',  -- S@rdines88
  'Doviakon',
  'Daniel',
  'super_admin'
on conflict (email) do nothing;

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 005_tondo_signalements_logs.sql
-- ─────────────────────────────────────────────────────────────────────────

-- ============================================================================
--  Tondo — Tables signalements + logs + vue unifiée transactions
--  À exécuter APRÈS 004_tondo_admins.sql.
-- ============================================================================

-- ----------------------------------------------------------------------------
--  1. Enums
-- ----------------------------------------------------------------------------
do $$
begin
  if not exists (select 1 from pg_type where typname = 'tondo_motif_signalement') then
    create type public.tondo_motif_signalement as enum (
      'fraude_suspectee',
      'contenu_inapproprie',
      'doublon',
      'autre'
    );
  end if;

  if not exists (select 1 from pg_type where typname = 'tondo_statut_signalement') then
    create type public.tondo_statut_signalement as enum (
      'nouveau',
      'en_traitement',
      'resolu',
      'rejete'
    );
  end if;

  if not exists (select 1 from pg_type where typname = 'tondo_niveau_log') then
    create type public.tondo_niveau_log as enum (
      'info',
      'warning',
      'error'
    );
  end if;
end $$;

-- ----------------------------------------------------------------------------
--  2. tondo_signalements
-- ----------------------------------------------------------------------------
create table if not exists public.tondo_signalements (
  id                  uuid primary key default gen_random_uuid(),
  project_id          uuid not null references public.projects(id) on delete restrict,
  cagnotte_id         uuid not null references public.tondo_cagnottes(id) on delete cascade,
  signale_par_user_id uuid references public.users(id) on delete set null,
  signale_par_libelle text not null,        -- dénormalisé pour audit même si l'user est supprimé

  motif               public.tondo_motif_signalement not null,
  description         text not null,
  statut              public.tondo_statut_signalement not null default 'nouveau',

  resolu_par_admin_id uuid references public.tondo_admins(id) on delete set null,
  resolu_le           timestamptz,
  resolu_commentaire  text,

  date_creation       timestamptz not null default now(),
  created_at          timestamptz not null default now(),
  updated_at          timestamptz not null default now()
);

create index if not exists tondo_signalements_cagnotte_id_idx on public.tondo_signalements (cagnotte_id);
create index if not exists tondo_signalements_statut_idx on public.tondo_signalements (statut);
create index if not exists tondo_signalements_project_id_idx on public.tondo_signalements (project_id);
create index if not exists tondo_signalements_date_creation_idx on public.tondo_signalements (date_creation desc);

drop trigger if exists trg_tondo_signalements_updated_at on public.tondo_signalements;
create trigger trg_tondo_signalements_updated_at
  before update on public.tondo_signalements
  for each row execute function public.set_updated_at();

alter table public.tondo_signalements enable row level security;

drop policy if exists "tondo_signalements_all_same_project" on public.tondo_signalements;
create policy "tondo_signalements_all_same_project" on public.tondo_signalements
  for all
  using (project_id = public.current_project_id())
  with check (project_id = public.current_project_id());

-- ----------------------------------------------------------------------------
--  3. tondo_logs
-- ----------------------------------------------------------------------------
create table if not exists public.tondo_logs (
  id                uuid primary key default gen_random_uuid(),
  project_id        uuid not null references public.projects(id) on delete restrict,

  acteur_admin_id   uuid references public.tondo_admins(id) on delete set null,
  acteur_user_id    uuid references public.users(id) on delete set null,
  acteur_libelle    text not null,                  -- ex : "Daniel Doviakon" ou "Système"
  acteur_role       text not null,                  -- super_admin / admin / operateur / lecteur / systeme

  action            text not null,
  cible             text,                            -- description libre de la cible
  niveau            public.tondo_niveau_log not null default 'info',
  metadonnees       jsonb,

  date              timestamptz not null default now(),
  created_at        timestamptz not null default now()
);

create index if not exists tondo_logs_date_idx on public.tondo_logs (date desc);
create index if not exists tondo_logs_niveau_idx on public.tondo_logs (niveau);
create index if not exists tondo_logs_acteur_role_idx on public.tondo_logs (acteur_role);
create index if not exists tondo_logs_project_id_idx on public.tondo_logs (project_id);

alter table public.tondo_logs enable row level security;

drop policy if exists "tondo_logs_all_same_project" on public.tondo_logs;
create policy "tondo_logs_all_same_project" on public.tondo_logs
  for all
  using (project_id = public.current_project_id())
  with check (project_id = public.current_project_id());

-- ----------------------------------------------------------------------------
--  4. Vue unifiée des transactions (payin + payout + payout_paynala)
--     Utilisée par le dashboard pour afficher une timeline globale.
-- ----------------------------------------------------------------------------
create or replace view public.tondo_transactions_unified as
  select
    id,
    'payin'::text as type,
    project_id,
    cagnotte_id,
    user_id,
    trans_id,
    operateur_id,
    numero_tel,
    montant,
    statut,
    request,
    response,
    date_creation,
    created_at,
    updated_at
  from public.tondo_payin

  union all

  select
    id,
    'payout'::text as type,
    project_id,
    cagnotte_id,
    user_id,
    trans_id,
    operateur_id,
    numero_tel,
    montant,
    statut,
    request,
    response,
    date_creation,
    created_at,
    updated_at
  from public.tondo_payout

  union all

  select
    id,
    'payout_paynala'::text as type,
    project_id,
    cagnotte_id,
    null::uuid as user_id,
    trans_id,
    operateur_id,
    null::text as numero_tel,
    montant,
    statut,
    request,
    response,
    date_creation,
    created_at,
    updated_at
  from public.tondo_payout_paynala;

comment on view public.tondo_transactions_unified is
  'Union des 3 tables transactionnelles. Lecture-seule. Filtrer par project_id et type côté requête.';

grant select on public.tondo_transactions_unified to authenticated;

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 007_tondo_participants_retrait.sql
-- ─────────────────────────────────────────────────────────────────────────

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

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 010_tondo_penalites_tontine.sql
-- ─────────────────────────────────────────────────────────────────────────

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

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 011_tondo_certifie_majeur.sql
-- ─────────────────────────────────────────────────────────────────────────

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

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 012_tonji_associations.sql
-- ─────────────────────────────────────────────────────────────────────────

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

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 013_tonji_canal_transid.sql
-- ─────────────────────────────────────────────────────────────────────────

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

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 014_tonji_storage_bucket.sql
-- ─────────────────────────────────────────────────────────────────────────

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

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 015_tonji_payin_montant_net.sql
-- ─────────────────────────────────────────────────────────────────────────

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

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 016_tonji_paiements_actif.sql
-- ─────────────────────────────────────────────────────────────────────────

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

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 017_migration_frais_retrait.sql
-- ─────────────────────────────────────────────────────────────────────────

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
-- ⚠️ PROD (`tondo_`). En DEV, remplacer par `tondo_`.
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
  FROM public.tondo_payin
  GROUP BY cagnotte_id
),
sortie AS (
  SELECT cagnotte_id, SUM(montant) AS total_payout
  FROM public.tondo_payout WHERE statut = 'succes' GROUP BY cagnotte_id
)
SELECT c.reference,
       c.titre,
       c.montant_collecte                                                       AS solde_actuel,
       GREATEST(0, COALESCE(d.total_du, 0) - COALESCE(s.total_payout, 0))        AS solde_corrige,
       GREATEST(0, COALESCE(d.total_du, 0) - COALESCE(s.total_payout, 0))
         - c.montant_collecte                                                   AS variation
FROM public.tondo_cagnottes c
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
UPDATE public.tondo_payin
SET montant_net = round(montant / 1.02)::bigint
WHERE statut = 'succes'
  AND montant > round(COALESCE(montant_net, 0) * 1.02) + 1;

-- 2) Recalculer le solde de chaque cagnotte = Σ dû(succès) − Σ payouts(succès).
--    (Répare aussi au passage les sur-crédits / crédits manqués = réconciliation.)
UPDATE public.tondo_cagnottes c
SET montant_collecte = GREATEST(0,
      COALESCE((SELECT SUM(COALESCE(p.montant_net, p.montant))
                FROM public.tondo_payin p
                WHERE p.cagnotte_id = c.id AND p.statut = 'succes'), 0)
    - COALESCE((SELECT SUM(o.montant)
                FROM public.tondo_payout o
                WHERE o.cagnotte_id = c.id AND o.statut = 'succes'), 0)),
    updated_at = now();

COMMIT;

-- ============================================================================
-- FIN 017_migration_frais_retrait.sql
-- ============================================================================

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 018_tonji_plafonds_cagnotte.sql
-- ─────────────────────────────────────────────────────────────────────────

-- ============================================================================
-- 018_tondo_plafonds_cagnotte.sql
-- Plafonds TOTAUX de collecte d'une cagnotte (éditables par les super_admin).
--
--   - plafond_cagnotte_particulier : 2 500 000 FCFA (cagnottes de particuliers)
--   - plafond_cagnotte_association : 10 000 000 FCFA (cagnottes d'associations,
--     loi n°35/62 ; sert de défaut, le plafond par organisation reste dans
--     tondo_organisations.plafond_fcfa)
--
-- Stockés sur la config projet (par opérateur/pays ; Tonji = airtel/GA).
-- Appliqués à la cotisation : on refuse si montant_collecte + montant dépasserait
-- le plafond du type de cagnotte.
--
-- ⚠️ PROD (`tondo_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

ALTER TABLE public.tondo_project_config
  ADD COLUMN IF NOT EXISTS plafond_cagnotte_particulier integer NOT NULL DEFAULT 2500000;

ALTER TABLE public.tondo_project_config
  ADD COLUMN IF NOT EXISTS plafond_cagnotte_association integer NOT NULL DEFAULT 10000000;

-- ============================================================================
-- FIN 018_tondo_plafonds_cagnotte.sql
-- ============================================================================

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 019_tonji_frais_retrait.sql
-- ─────────────────────────────────────────────────────────────────────────

-- ============================================================================
-- 019_tondo_frais_retrait.sql
-- Frais de retrait CONFIGURABLES, en matrice (type de cotisation × type de user).
--
-- On avait retiré les frais de retrait (~3 %) du cotisant (018/amendement RÈGLE 3).
-- On les rend maintenant CONFIGURABLES par croisement :
--     type de cotisation : cagnotte | tontine
--     type de user       : particulier | association
-- → un TAUX (décimal, ex : 0.03 = 3 %) par cellule. Défaut 0 (= état actuel,
--   aucun frais de retrait facturé au cotisant).
--
-- Appliqué au calcul : montant_brut = ceil( net × (1 + frais_retrait) × (1 + commission) ).
-- Stocké en JSON sur la config projet (tondo_project_config).
--
-- ⚠️ PROD (`tondo_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

ALTER TABLE public.tondo_project_config
  ADD COLUMN IF NOT EXISTS frais_retrait json NOT NULL
  DEFAULT '{"cagnotte":{"particulier":0,"association":0},"tontine":{"particulier":0,"association":0}}'::json;

-- ============================================================================
-- FIN 019_tondo_frais_retrait.sql
-- ============================================================================

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 020_tonji_device_tokens.sql
-- ─────────────────────────────────────────────────────────────────────────

-- ============================================================================
-- 020_tondo_device_tokens.sql
-- Jetons push des appareils (FCM registration tokens).
--
-- Migration OneSignal → FCM/APNs en direct (gratuit à l'échelle, cf. plafond
-- MAU du plan gratuit OneSignal au 01/10/2026). OneSignal gérait pour nous le
-- mapping external_id ↔ device ; désormais c'est NOUS qui stockons, par user,
-- le(s) token(s) FCM de ses appareils. Le backend envoie ensuite chaque push
-- via l'API FCM HTTP v1 (un message par token).
--
-- Un même appareil (token) n'appartient qu'à UN user à la fois (le dernier
-- connecté) : à la connexion l'app (ré)enregistre son token → on réaffecte le
-- user_id ; à la déconnexion l'app supprime la ligne.
--
-- Style repris de 012_tondo_associations.sql (PK uuid gen_random_uuid,
-- project_id -> projects(id), trigger updated_at, RLS + policy projet, grants).
--
-- ⚠️ PROD (`tondo_`). En DEV (itgjlhaalodlgwsyrjnz), remplacer par `tondo_`.
--    IDEMPOTENT. À jouer dans le SQL Editor Supabase (la prod ne passe PAS par
--    `artisan migrate`).
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.tondo_device_tokens (
    id          uuid        DEFAULT gen_random_uuid() NOT NULL,
    project_id  uuid        NOT NULL,
    user_id     uuid        NOT NULL,          -- propriétaire actuel de l'appareil
    token       text        NOT NULL,          -- registration token FCM (Android + iOS via Firebase)
    plateforme  varchar(10) NOT NULL,          -- 'android' | 'ios'
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);

-- Clé primaire
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conname = 'tondo_device_tokens_pkey'
      AND conrelid = 'public.tondo_device_tokens'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_device_tokens
      ADD CONSTRAINT tondo_device_tokens_pkey PRIMARY KEY (id);
  END IF;
END $$;

-- Clés étrangères (projet + user)
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_device_tokens_project_fk'
      AND conrelid = 'public.tondo_device_tokens'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_device_tokens
      ADD CONSTRAINT tondo_device_tokens_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_device_tokens_user_fk'
      AND conrelid = 'public.tondo_device_tokens'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_device_tokens
      ADD CONSTRAINT tondo_device_tokens_user_fk
      FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;
  END IF;
END $$;

-- Contrainte de domaine sur la plateforme
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_device_tokens_plateforme_check'
      AND conrelid = 'public.tondo_device_tokens'::regclass
  ) THEN
    ALTER TABLE public.tondo_device_tokens
      ADD CONSTRAINT tondo_device_tokens_plateforme_check
      CHECK (plateforme IN ('android', 'ios'));
  END IF;
END $$;

-- Un token est unique par projet (le re-dépôt réaffecte simplement le user_id)
CREATE UNIQUE INDEX IF NOT EXISTS tondo_device_tokens_project_token_idx
  ON public.tondo_device_tokens (project_id, token);
-- Envoi ciblé : récupérer tous les tokens d'un user
CREATE INDEX IF NOT EXISTS tondo_device_tokens_user_idx
  ON public.tondo_device_tokens (project_id, user_id);

-- Maintien automatique de updated_at
DROP TRIGGER IF EXISTS trg_tondo_device_tokens_updated_at ON public.tondo_device_tokens;
CREATE TRIGGER trg_tondo_device_tokens_updated_at
  BEFORE UPDATE ON public.tondo_device_tokens
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- RLS + policy de cloisonnement projet (accès PostgREST ; Laravel = owner, bypass)
ALTER TABLE public.tondo_device_tokens ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tondo_device_tokens_all_same_project ON public.tondo_device_tokens;
CREATE POLICY tondo_device_tokens_all_same_project ON public.tondo_device_tokens
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

-- Droits
GRANT SELECT, INSERT, UPDATE, DELETE ON public.tondo_device_tokens TO authenticated;
GRANT ALL ON public.tondo_device_tokens TO service_role;

-- ============================================================================
-- FIN 020_tondo_device_tokens.sql
-- ============================================================================

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 021_tonji_plafond.sql
-- ─────────────────────────────────────────────────────────────────────────

-- ============================================================================
-- 021_tondo_plafond.sql
-- Plafond de collecte PERSONNALISÉ par compte + demandes de déblocage.
--
-- Contexte : jusqu'ici le plafond total d'une cagnotte est global par type de
-- compte (particulier ~2,5 M / association 10 M, config projet). On ajoute un
-- override PAR COMPTE :
--   - association → `tondo_organisations.plafond_fcfa` (déjà existant) ;
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
-- ⚠️ PROD (`tondo_`). En DEV (itgjlhaalodlgwsyrjnz), remplacer par `tondo_`.
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
CREATE TABLE IF NOT EXISTS public.tondo_plafond_demandes (
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
    WHERE conname = 'tondo_plafond_demandes_pkey'
      AND conrelid = 'public.tondo_plafond_demandes'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_plafond_demandes
      ADD CONSTRAINT tondo_plafond_demandes_pkey PRIMARY KEY (id);
  END IF;
END $$;

-- Clés étrangères (projet + user)
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_plafond_demandes_project_fk'
      AND conrelid = 'public.tondo_plafond_demandes'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_plafond_demandes
      ADD CONSTRAINT tondo_plafond_demandes_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_plafond_demandes_user_fk'
      AND conrelid = 'public.tondo_plafond_demandes'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_plafond_demandes
      ADD CONSTRAINT tondo_plafond_demandes_user_fk
      FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;
  END IF;
END $$;

-- Contrainte de domaine sur le statut
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_plafond_demandes_statut_check'
      AND conrelid = 'public.tondo_plafond_demandes'::regclass
  ) THEN
    ALTER TABLE public.tondo_plafond_demandes
      ADD CONSTRAINT tondo_plafond_demandes_statut_check
      CHECK (statut IN ('en_attente', 'approuve', 'rejete'));
  END IF;
END $$;

-- Une seule demande "vivante" par user : on remplace au re-dépôt (index sur user)
CREATE INDEX IF NOT EXISTS tondo_plafond_demandes_user_idx
  ON public.tondo_plafond_demandes (project_id, user_id);
-- File de modération par statut
CREATE INDEX IF NOT EXISTS tondo_plafond_demandes_statut_idx
  ON public.tondo_plafond_demandes (project_id, statut);

-- Maintien automatique de updated_at
DROP TRIGGER IF EXISTS trg_tondo_plafond_demandes_updated_at ON public.tondo_plafond_demandes;
CREATE TRIGGER trg_tondo_plafond_demandes_updated_at
  BEFORE UPDATE ON public.tondo_plafond_demandes
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- RLS + policy de cloisonnement projet
ALTER TABLE public.tondo_plafond_demandes ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tondo_plafond_demandes_all_same_project ON public.tondo_plafond_demandes;
CREATE POLICY tondo_plafond_demandes_all_same_project ON public.tondo_plafond_demandes
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

-- Droits
GRANT SELECT, INSERT, UPDATE, DELETE ON public.tondo_plafond_demandes TO authenticated;
GRANT ALL ON public.tondo_plafond_demandes TO service_role;

-- ============================================================================
-- FIN 021_tondo_plafond.sql
-- ============================================================================

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 022_tonji_commentaire_cotisation.sql
-- ─────────────────────────────────────────────────────────────────────────

-- ============================================================================
-- 022_tondo_commentaire_cotisation.sql
-- Commentaire libre et OPTIONNEL laissé par le cotisant au moment de payer.
--
-- Usage prévu : préciser une particularité du don, ou indiquer que l'on cotise
-- pour quelqu'un d'autre (« pour ma mère », « part de Jean », …).
--
-- Deux colonnes, pas une : sur Airtel le paiement est confirmé en ASYNCHRONE.
-- La ligne `tondo_paiements` n'est créée qu'à la confirmation, à partir de la
-- ligne `tondo_payin`. Le commentaire doit donc être porté par le payin dès
-- l'initiation pour survivre au polling ET à la réconciliation
-- (`tonji:reconcilier-payins`), qui recrée les paiements depuis les payin.
--
-- Champ purement descriptif : jamais utilisé dans un calcul, ni dans une règle
-- de gestion. Visible du gérant de la cagnotte et du cotisant, jamais publié
-- sur la page publique d'une cagnotte ouverte (surface d'abus).
--
-- ⚠️ PROD (`tondo_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

ALTER TABLE public.tondo_payin
  ADD COLUMN IF NOT EXISTS commentaire text;

ALTER TABLE public.tondo_paiements
  ADD COLUMN IF NOT EXISTS commentaire text;


-- ----------------------------------------------------------------------------
-- Vue unifiée du dashboard : expose `commentaire` sur les lignes payin.
--
-- Ajouté EN FIN de chaque SELECT : CREATE OR REPLACE VIEW n'autorise que
-- l'ajout de colonnes en queue, jamais l'insertion au milieu.
-- payout / payout_paynala sont des sorties système → commentaire NULL.
-- ----------------------------------------------------------------------------
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
    tondo_payin.canal,
    tondo_payin.commentaire
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
    NULL::varchar AS canal,
    NULL::text AS commentaire
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
    NULL::varchar AS canal,
    NULL::text AS commentaire
   FROM public.tondo_payout_paynala;

GRANT SELECT ON public.tondo_transactions_unified TO authenticated;
GRANT SELECT ON public.tondo_transactions_unified TO service_role;

-- ============================================================================
-- FIN 022_tondo_commentaire_cotisation.sql
-- ============================================================================

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 023_cgu_acceptation.sql
-- ─────────────────────────────────────────────────────────────────────────

-- ============================================================================
-- 023_cgu_acceptation.sql
-- Mémorise quelle version des CGU un utilisateur a acceptée.
--
-- Les CGU sont générées à partir de la configuration opérateur
-- (GET /api/mobile/config/cgu) et portent une `version` : l'empreinte du texte
-- effectivement affiché. Changer un plafond, la matrice des frais de retrait ou
-- une formulation produit une nouvelle version ; un réglage qui n'apparaît pas
-- dans le texte n'en produit pas.
--
-- Comparer `cgu_version` à la version courante dit si l'utilisateur doit
-- réaccepter. NULL = n'a jamais accepté (comptes antérieurs à ce mécanisme).
--
-- ⚠️ La table `users` n'est PAS préfixée : ce fichier est identique en DEV et
-- en PROD, contrairement aux tables tondo_/tondo_. IDEMPOTENT.
-- ============================================================================

ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS cgu_version text;

ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS cgu_acceptee_at timestamptz;

COMMENT ON COLUMN public.users.cgu_version IS
  'Empreinte du texte des CGU accepté par l''utilisateur (GET /api/mobile/config/cgu).';

-- ============================================================================
-- FIN 023_cgu_acceptation.sql
-- ============================================================================

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 024_associations_sans_dossier.sql
-- ─────────────────────────────────────────────────────────────────────────

-- ============================================================================
-- 024_associations_sans_dossier.sql
-- Les associations ne fournissent plus de pièces justificatives.
--
-- Décision du 2026-09-09 : un compte associatif existe chez Airtel, qui a déjà
-- instruit l'identité au titre de son propre KYC. Paynala ne redemande donc
-- plus le récépissé, les statuts, le PV, la pièce d'identité ni l'autorisation
-- de collecte. Une association est acceptée d'emblée et accède directement à
-- l'accueil.
--
-- Le seul dossier encore étudié dans le dashboard est la demande de
-- dépassement du plafond de 10 M FCFA (`tondo_plafond_demandes`).
--
-- La table `tondo_organisation_documents` est VOLONTAIREMENT CONSERVÉE : elle
-- n'est plus ni écrite ni lue, mais resservira si des pièces sont exigées à
-- l'appui d'une demande de dépassement. Aucune donnée n'est supprimée.
--
-- ⚠️ PROD (`tondo_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

-- Nouveau défaut : approuvée dès la création.
ALTER TABLE public.tondo_organisations
  ALTER COLUMN statut SET DEFAULT 'approuve';

-- Les dossiers restés en attente n'ont plus de raison de l'être : personne ne
-- viendra les instruire, et leurs titulaires seraient bloqués hors de l'app.
-- Les statuts 'rejete' et 'suspendu' ne sont PAS touchés : ce sont des
-- décisions de modération, pas des dossiers en cours d'instruction.
UPDATE public.tondo_organisations
   SET statut = 'approuve', updated_at = now()
 WHERE statut = 'en_attente';

-- ============================================================================
-- FIN 024_associations_sans_dossier.sql
-- ============================================================================

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 025_backfill_type_compte.sql
-- ─────────────────────────────────────────────────────────────────────────

-- ============================================================================
-- 025_backfill_type_compte.sql
-- Renseigne `type_compte` pour les comptes antérieurs à la bascule associations.
--
-- Ces comptes sont à NULL et sont affichés « Non choisi » dans le dashboard.
-- Ils sont aiguillés vers l'écran de choix à chaque connexion.
--
-- ⚠️ ON NE LES MET PAS TOUS À 'particulier'. Un compte à NULL peut être une
-- association qui ne s'est jamais vue proposer le choix : le figer en
-- particulier contredirait son grade Airtel, et comme le gate ne se déclenche
-- que sur NULL, l'erreur ne serait jamais corrigée.
--
-- Seuls sont remplis ceux dont on est CERTAIN. `type_client` est stocké et
-- dérive du même grade que `type_compte` :
--   type_client = 'particulier'  ⟹  grade SUBS ou TEMP  ⟹  particulier, certain
--   type_client = 'entreprise'   ⟹  grade ≠ SUBS/TEMP   ⟹  peut être MERCHVIP
--                                                          ou HMERA, donc une
--                                                          association
--
-- Les autres restent à NULL et passeront par l'écran de choix, qui confronte
-- désormais le choix au KYC : ils seront correctement classés, ou bloqués si
-- leur profil n'est pas reconnu.
--
-- ⚠️ La table `users` n'est PAS préfixée : identique en DEV et en PROD.
-- IDEMPOTENT.
-- ============================================================================

UPDATE public.users
   SET type_compte = 'particulier',
       updated_at  = now()
 WHERE type_compte IS NULL
   AND type_client = 'particulier';

-- Contrôle : ce qui reste à NULL après coup, par type_client.
-- Attendu : uniquement des 'entreprise' / 'marchand' / NULL.
--   SELECT type_client, count(*) FROM public.users
--    WHERE type_compte IS NULL GROUP BY type_client;

-- ============================================================================
-- FIN 025_backfill_type_compte.sql
-- ============================================================================

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 026_tonji_evenements.sql
-- ─────────────────────────────────────────────────────────────────────────

-- ============================================================================
-- 026_tondo_evenements.sql
-- Télémétrie produit : ce que les utilisateurs font dans l'app.
--
-- Distincte de `tondo_logs`, qui journalise les actions ADMIN (acteur_admin_id,
-- rôles super_admin/systeme) à des fins d'audit. Ici il s'agit du comportement
-- des utilisateurs finaux, à des fins d'analyse produit.
--
-- ⚠️ AUCUN CONTENU SAISI n'entre dans cette table. Pas de numéro, pas de
-- montant exact (des tranches uniquement), pas de commentaire de cotisation.
-- Seule exception assumée : le terme cherché dans l'annuaire public, qui n'est
-- pas une donnée personnelle et répond à une question produit directe.
--
-- `id` est généré par le CLIENT et sert de clé d'idempotence : un lot renvoyé
-- après un timeout ne doit pas doubler les compteurs.
--
-- `occurred_at` est l'horodatage CLIENT (l'app bufferise et peut être hors
-- ligne) ; `created_at` est celui du serveur. L'écart entre les deux mesure la
-- latence de remontée.
--
-- ⚠️ PROD (`tondo_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.tondo_evenements (
    id           uuid PRIMARY KEY,
    project_id   uuid NOT NULL REFERENCES public.projects(id) ON DELETE RESTRICT,
    -- Nullable : un événement peut précéder la création du compte (inscription).
    -- ON DELETE SET NULL : la suppression d'un compte anonymise sa télémétrie
    -- sans détruire les compteurs.
    user_id      uuid REFERENCES public.users(id) ON DELETE SET NULL,
    -- Généré au lancement de l'app : permet de reconstituer un parcours.
    session_id   text NOT NULL,
    nom          text NOT NULL,
    canal        text NOT NULL DEFAULT 'app',
    plateforme   text,
    version_app  text,
    contexte     jsonb NOT NULL DEFAULT '{}'::jsonb,
    occurred_at  timestamptz NOT NULL,
    created_at   timestamptz NOT NULL DEFAULT now()
);

-- Lecture principale du dashboard : un événement sur une période.
CREATE INDEX IF NOT EXISTS tondo_evenements_nom_date_idx
  ON public.tondo_evenements (project_id, nom, occurred_at DESC);

-- Reconstitution d'un parcours complet.
CREATE INDEX IF NOT EXISTS tondo_evenements_session_idx
  ON public.tondo_evenements (session_id, occurred_at);

-- Purge des données brutes au-delà de la rétention (90 jours).
CREATE INDEX IF NOT EXISTS tondo_evenements_created_idx
  ON public.tondo_evenements (created_at);

ALTER TABLE public.tondo_evenements ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "tondo_evenements_same_project" ON public.tondo_evenements;
CREATE POLICY "tondo_evenements_same_project" ON public.tondo_evenements
  FOR ALL
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

GRANT SELECT, INSERT ON public.tondo_evenements TO authenticated;
GRANT ALL    ON public.tondo_evenements TO service_role;

COMMENT ON TABLE public.tondo_evenements IS
  'Télémétrie produit (comportement utilisateur). Aucun contenu saisi : ni numéro, ni montant exact, ni commentaire.';

-- ============================================================================
-- FIN 026_tondo_evenements.sql
-- ============================================================================

-- ─────────────────────────────────────────────────────────────────────────
-- ▼ 027_tonji_evenements_jour.sql
-- ─────────────────────────────────────────────────────────────────────────

-- ============================================================================
-- 027_tondo_evenements_jour.sql
-- Agrégats quotidiens de la télémétrie produit.
--
-- Le dashboard lit CETTE table, jamais `tondo_evenements`. À la cible de
-- fréquentation, compter des millions de lignes brutes à chaque affichage
-- rendrait la page inutilisable — et le coût grandit chaque jour, alors que
-- l'agrégat, lui, reste stable.
--
-- Rétention : les lignes brutes sont purgées à 90 jours, ces agrégats sont
-- conservés. C'est ce qui permet de comparer un entonnoir à celui d'il y a un
-- an sans garder un an d'événements unitaires.
--
-- `plateforme` vaut '' plutôt que NULL : en Postgres, deux NULL ne sont pas
-- égaux, et l'index unique ne dédoublonnerait pas les lignes sans plateforme.
--
-- ⚠️ PROD (`tondo_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.tondo_evenements_jour (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id   uuid NOT NULL REFERENCES public.projects(id) ON DELETE RESTRICT,
    jour         date NOT NULL,
    nom          text NOT NULL,
    canal        text NOT NULL DEFAULT 'app',
    plateforme   text NOT NULL DEFAULT '',
    compte                int NOT NULL DEFAULT 0,
    utilisateurs_uniques  int NOT NULL DEFAULT 0,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);

-- Clé de l'agrégat : permet le recalcul par UPSERT. Le job repasse sur une
-- fenêtre glissante, car l'app bufferise et peut remonter hier aujourd'hui.
CREATE UNIQUE INDEX IF NOT EXISTS tondo_evenements_jour_cle_idx
  ON public.tondo_evenements_jour (project_id, jour, nom, canal, plateforme);

-- Lecture du dashboard : une série sur une période.
CREATE INDEX IF NOT EXISTS tondo_evenements_jour_serie_idx
  ON public.tondo_evenements_jour (project_id, nom, jour DESC);

ALTER TABLE public.tondo_evenements_jour ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "tondo_evenements_jour_same_project" ON public.tondo_evenements_jour;
CREATE POLICY "tondo_evenements_jour_same_project" ON public.tondo_evenements_jour
  FOR ALL
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

GRANT SELECT ON public.tondo_evenements_jour TO authenticated;
GRANT ALL    ON public.tondo_evenements_jour TO service_role;

COMMENT ON TABLE public.tondo_evenements_jour IS
  'Agrégats quotidiens de télémétrie. Lus par le dashboard ; les lignes brutes sont purgées à 90 jours.';

-- ============================================================================
-- FIN 027_tondo_evenements_jour.sql
-- ============================================================================
