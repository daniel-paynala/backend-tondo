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
