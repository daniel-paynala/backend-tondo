-- ============================================================================
-- 041a_tonji_type_wallet.sql
-- Le type de collecte « wallet » — À JOUER SEUL, ET EN PREMIER.
--
-- ── Pourquoi ce fichier est séparé ──────────────────────────────────────────
--
-- PostgreSQL refuse qu'une nouvelle valeur d'énumération soit UTILISÉE dans la
-- transaction qui l'ajoute :
--
--     ERROR 55P04: unsafe use of new value "wallet" of enum type
--     HINT: New enum values must be committed before they can be used.
--
-- L'éditeur SQL de Supabase enveloppe tout le script dans une transaction. Le
-- `CREATE INDEX ... WHERE type = 'wallet'` de la suite tombait donc sur cette
-- règle. Les deux moitiés sont séparées pour que l'ajout soit commité avant
-- qu'on s'en serve.
--
--   1. ce fichier
--   2. puis 041b_tonji_wallet.sql
--
-- Le nom de l'énumération n'est pas écrit mais LU sur la colonne : en
-- production les tables portent `tonji_` alors que le type a pu rester
-- `tondo_`, selon l'ordre dans lequel les scripts ont été joués.
--
-- Idempotent : rejouable sans effet de bord.
-- ============================================================================

DO $$
DECLARE nom_type text;
BEGIN
  SELECT t.typname
    INTO nom_type
    FROM pg_attribute a
    JOIN pg_type     t ON t.oid = a.atttypid
   WHERE a.attrelid = 'public.tonji_cagnottes'::regclass
     AND a.attname  = 'type'
     AND t.typtype  = 'e';   -- énumération seulement

  IF nom_type IS NULL THEN
    RAISE NOTICE 'Colonne type non énumérée : rien à ajouter.';
  ELSE
    EXECUTE format('ALTER TYPE public.%I ADD VALUE IF NOT EXISTS %L', nom_type, 'wallet');
  END IF;
END $$;
