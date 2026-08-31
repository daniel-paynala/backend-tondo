-- ============================================================================
-- 020_tonji_device_tokens.sql
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
-- Style repris de 012_tonji_associations.sql (PK uuid gen_random_uuid,
-- project_id -> projects(id), trigger updated_at, RLS + policy projet, grants).
--
-- ⚠️ PROD (`tonji_`). En DEV (itgjlhaalodlgwsyrjnz), remplacer par `tondo_`.
--    IDEMPOTENT. À jouer dans le SQL Editor Supabase (la prod ne passe PAS par
--    `artisan migrate`).
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.tonji_device_tokens (
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
    WHERE conname = 'tonji_device_tokens_pkey'
      AND conrelid = 'public.tonji_device_tokens'::regclass
  ) THEN
    ALTER TABLE ONLY public.tonji_device_tokens
      ADD CONSTRAINT tonji_device_tokens_pkey PRIMARY KEY (id);
  END IF;
END $$;

-- Clés étrangères (projet + user)
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tonji_device_tokens_project_fk'
      AND conrelid = 'public.tonji_device_tokens'::regclass
  ) THEN
    ALTER TABLE ONLY public.tonji_device_tokens
      ADD CONSTRAINT tonji_device_tokens_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tonji_device_tokens_user_fk'
      AND conrelid = 'public.tonji_device_tokens'::regclass
  ) THEN
    ALTER TABLE ONLY public.tonji_device_tokens
      ADD CONSTRAINT tonji_device_tokens_user_fk
      FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;
  END IF;
END $$;

-- Contrainte de domaine sur la plateforme
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tonji_device_tokens_plateforme_check'
      AND conrelid = 'public.tonji_device_tokens'::regclass
  ) THEN
    ALTER TABLE public.tonji_device_tokens
      ADD CONSTRAINT tonji_device_tokens_plateforme_check
      CHECK (plateforme IN ('android', 'ios'));
  END IF;
END $$;

-- Un token est unique par projet (le re-dépôt réaffecte simplement le user_id)
CREATE UNIQUE INDEX IF NOT EXISTS tonji_device_tokens_project_token_idx
  ON public.tonji_device_tokens (project_id, token);
-- Envoi ciblé : récupérer tous les tokens d'un user
CREATE INDEX IF NOT EXISTS tonji_device_tokens_user_idx
  ON public.tonji_device_tokens (project_id, user_id);

-- Maintien automatique de updated_at
DROP TRIGGER IF EXISTS trg_tonji_device_tokens_updated_at ON public.tonji_device_tokens;
CREATE TRIGGER trg_tonji_device_tokens_updated_at
  BEFORE UPDATE ON public.tonji_device_tokens
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- RLS + policy de cloisonnement projet (accès PostgREST ; Laravel = owner, bypass)
ALTER TABLE public.tonji_device_tokens ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tonji_device_tokens_all_same_project ON public.tonji_device_tokens;
CREATE POLICY tonji_device_tokens_all_same_project ON public.tonji_device_tokens
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

-- Droits
GRANT SELECT, INSERT, UPDATE, DELETE ON public.tonji_device_tokens TO authenticated;
GRANT ALL ON public.tonji_device_tokens TO service_role;

-- ============================================================================
-- FIN 020_tonji_device_tokens.sql
-- ============================================================================
