-- ============================================================================
-- 031_tonji_marchands_numero_partage.sql
-- Plusieurs marchands peuvent partager le même numéro Airtel Money.
--
-- 029 imposait un numéro unique par projet, en supposant qu'un numéro désigne
-- un établissement. C'est faux : une chaîne de restaurants encaisse sur un
-- seul numéro tout en ayant plusieurs points de vente, et chacun mérite sa
-- fiche — c'est le marchand choisi au paiement qui dit où l'on a payé, pas le
-- numéro.
--
-- L'index unique devient donc un index simple : il sert encore à retrouver les
-- fiches d'un numéro, sans rien interdire. Le dashboard signale le partage à
-- la saisie, mais ne le bloque plus.
--
-- ⚠️ PROD (`tonji_`). En TEST, jouer `TEST_031_tondo_marchands_numero_partage.sql`.
-- IDEMPOTENT. À jouer dans le SQL Editor Supabase.
-- ============================================================================

DROP INDEX IF EXISTS public.tonji_marchands_numero_uidx;

-- Recherche par numéro : plusieurs fiches peuvent désormais répondre.
CREATE INDEX IF NOT EXISTS tonji_marchands_numero_idx
  ON public.tonji_marchands (project_id, numero_tel);

COMMENT ON COLUMN public.tonji_marchands.numero_tel IS
  'Numéro Airtel Money du marchand, format E.164 (+241XXXXXXXX). Peut être partagé par plusieurs fiches : une chaîne encaisse sur un seul numéro pour plusieurs établissements.';

-- ============================================================================
-- FIN 031_tonji_marchands_numero_partage.sql
-- ============================================================================
