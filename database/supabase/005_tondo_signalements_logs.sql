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
