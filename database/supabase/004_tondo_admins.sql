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
