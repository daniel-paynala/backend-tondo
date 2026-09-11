-- ============================================================================
--  TEST_tondo_partie1.sql  —  PARTIE 1/3 : Fondation et tables metier
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
--    001_fondation.sql
--    002_tondo.sql
--    003_tondo_transactions.sql
--    004_tondo_admins.sql
--    005_tondo_signalements_logs.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────
-- ▼ 001_fondation.sql
-- ─────────────────────────────────────────────────────────

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


-- ─────────────────────────────────────────────────────────
-- ▼ 002_tondo.sql
-- ─────────────────────────────────────────────────────────

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


-- ─────────────────────────────────────────────────────────
-- ▼ 003_tondo_transactions.sql
-- ─────────────────────────────────────────────────────────

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


-- ─────────────────────────────────────────────────────────
-- ▼ 004_tondo_admins.sql
-- ─────────────────────────────────────────────────────────

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


-- ─────────────────────────────────────────────────────────
-- ▼ 005_tondo_signalements_logs.sql
-- ─────────────────────────────────────────────────────────

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
