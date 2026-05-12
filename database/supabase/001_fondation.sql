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
