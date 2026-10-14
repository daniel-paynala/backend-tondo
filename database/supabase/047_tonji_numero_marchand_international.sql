-- ============================================================================
-- 047 — Le numéro d'un marchand n'est plus forcément gabonais
--       (PRODUCTION, préfixe tonji_)
--
-- La contrainte imposait `^\+241[0-9]{8,9}$` : aucune enseigne hors du Gabon
-- ne pouvait être enregistrée, et relâcher la validation PHP sans toucher à
-- celle-ci n'aurait fait que déplacer l'erreur — d'un message lisible vers une
-- erreur Postgres brute.
--
-- La nouvelle forme est celle de la norme E.164 : un `+`, un premier chiffre
-- non nul, puis 7 à 14 chiffres. Elle reste stricte sur ce qui compte — pas
-- d'espaces, pas de zéro de tête, pas de texte — sans nommer de pays.
--
-- Le rattachement d'un numéro à un pays ne se fait PAS ici : il se déduit des
-- indicatifs déclarés dans `project_config`, qui changent sans migration.
-- Graver la liste des pays dans une contrainte aurait recréé le problème qu'on
-- corrige, un cran plus bas.
--
-- Idempotent : rejouable sans dommage.
-- ============================================================================

ALTER TABLE public.tonji_marchands
  DROP CONSTRAINT IF EXISTS tonji_marchands_numero_check;

ALTER TABLE public.tonji_marchands
  ADD CONSTRAINT tonji_marchands_numero_check
  CHECK (numero_tel ~ '^\+[1-9][0-9]{7,14}$');
