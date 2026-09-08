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
-- en PROD, contrairement aux tables tondo_/tonji_. IDEMPOTENT.
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
