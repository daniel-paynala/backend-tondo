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
