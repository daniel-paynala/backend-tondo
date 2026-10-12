-- ============================================================================
-- 042 — Logo d'enseigne pour les marchands (PRODUCTION, préfixe tonji_)
--
-- Jusqu'ici une fiche marchand n'avait que sa pastille d'initiales. Les
-- commerces qui ont une identité visuelle veulent la voir, et le client
-- reconnaît un logo plus vite qu'il ne lit deux lettres.
--
-- Deux colonnes et non une :
--
--   logo_chemin   le chemin de l'objet dans le bucket Supabase, pas une URL.
--                 Stocker l'URL figerait le mode de distribution dans la base :
--                 le jour où les logos passent par un CDN, il faudrait réécrire
--                 chaque ligne. Le chemin, lui, ne change pas.
--
--   logo_maj_at   l'horodatage du dernier téléversement, qui sert à casser le
--                 cache. L'URL d'un logo est stable et mise en cache longtemps
--                 par les clients ; sans ce jeton, remplacer un logo ne
--                 changerait rien à l'écran de qui l'a déjà vu.
--
-- Idempotent : rejouable sans dommage.
-- ============================================================================

ALTER TABLE public.tonji_marchands
  ADD COLUMN IF NOT EXISTS logo_chemin text,
  ADD COLUMN IF NOT EXISTS logo_maj_at timestamptz;

COMMENT ON COLUMN public.tonji_marchands.logo_chemin IS
  'Chemin de l''objet dans le bucket Supabase (marchands/{id}/logo.{ext}). Null = pastille d''initiales.';
COMMENT ON COLUMN public.tonji_marchands.logo_maj_at IS
  'Dernier téléversement du logo — sert de jeton anti-cache dans l''URL servie aux clients.';

-- Le service_role lit et écrit ces colonnes comme le reste de la table : rien
-- à accorder de plus, les droits portent sur la table.
