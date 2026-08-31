-- ============================================================================
-- 014_tonji_storage_bucket.sql
-- Bucket de stockage des pièces des associations (Supabase Storage).
--
-- Crée un bucket PRIVÉ `tonji-documents` (public=false) : les objets ne sont
-- accessibles que via des URLs signées temporaires. Le backend Laravel et le
-- dashboard utilisent la clé service_role (qui bypass la RLS Storage), donc
-- AUCUNE policy sur storage.objects n'est nécessaire pour notre usage.
--
-- Limite de taille : 8 Mo (aligné avec la validation applicative).
--
-- ⚠️ À jouer sur CHAQUE base concernée : la PROD, et la DEV si tu testes en
--    local (le nom du bucket est identique). IDEMPOTENT.
-- ============================================================================

INSERT INTO storage.buckets (id, name, public, file_size_limit)
VALUES ('tonji-documents', 'tonji-documents', false, 8388608)  -- 8 Mo
ON CONFLICT (id) DO NOTHING;

-- ============================================================================
-- FIN 014_tonji_storage_bucket.sql
-- ============================================================================
