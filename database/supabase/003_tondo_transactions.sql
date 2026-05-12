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
