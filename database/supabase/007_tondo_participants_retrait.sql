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
