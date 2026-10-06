-- ============================================================================
-- 033_tonji_marchands_code.sql
-- Code marchand : l'identifiant que l'enseigne communique à ses clients.
--
-- Depuis 031, plusieurs fiches peuvent partager le même numéro Airtel Money —
-- une chaîne encaisse sur un seul numéro pour plusieurs points de vente. Taper
-- ce numéro ne suffit donc plus à désigner QUI est payé : il peut répondre
-- plusieurs établissements, et c'est le marchand retenu qui dira où l'argent
-- est allé. Le code lève cette ambiguïté.
--
-- Le code ne vient pas de Tonji : c'est une donnée de l'enseigne, saisie telle
-- quelle dans le dashboard par un super admin. D'où un champ **alphanumérique
-- libre** plutôt qu'un format maison — contraindre la forme reviendrait à
-- refuser des codes que les marchands utilisent déjà ailleurs.
--
-- Facultatif : une fiche sans code reste parfaitement utilisable, le client la
-- choisit alors dans le carnet ou par son numéro.
--
-- **Unicité insensible à la casse, par projet.** Le code sert à retrouver une
-- fiche à partir d'une saisie client : deux fiches partageant un code rendraient
-- cette résolution ambiguë, et le client paierait peut-être le mauvais
-- établissement. `barachois` et `BARACHOIS` désignent donc la même chose.
--
-- ⚠️ PROD (`tonji_`). En TEST, jouer `TEST_033_tondo_marchands_code.sql`.
-- IDEMPOTENT. À jouer dans le SQL Editor Supabase.
-- ============================================================================

ALTER TABLE public.tonji_marchands
  ADD COLUMN IF NOT EXISTS code_marchand varchar(32);

COMMENT ON COLUMN public.tonji_marchands.code_marchand IS
  'Code que l''enseigne communique à ses clients, saisi tel quel dans le dashboard. Alphanumérique, facultatif, unique par projet sans tenir compte de la casse. Sert à désigner une fiche précise quand plusieurs partagent un numéro.';

DO $$
BEGIN
  -- Forme volontairement permissive : le code vient du marchand, pas de nous.
  -- On n'écarte que ce qui ne pourrait pas être dicté ni saisi — espaces,
  -- accents, ponctuation exotique — et on impose deux caractères au minimum.
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_marchands_code_check') THEN
    ALTER TABLE public.tonji_marchands ADD CONSTRAINT tonji_marchands_code_check
      CHECK (code_marchand IS NULL OR code_marchand ~ '^[A-Za-z0-9][A-Za-z0-9._-]{1,31}$');
  END IF;
END $$;

-- Unicité par projet, insensible à la casse, et seulement quand un code existe :
-- l'index partiel laisse coexister autant de fiches sans code que nécessaire.
CREATE UNIQUE INDEX IF NOT EXISTS tonji_marchands_code_uidx
  ON public.tonji_marchands (project_id, upper(code_marchand))
  WHERE code_marchand IS NOT NULL;


-- ============================================================================
-- FIN 033_tonji_marchands_code.sql
-- ============================================================================
