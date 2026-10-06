-- ============================================================================
-- TEST_037_tondo_frais_marchand_fiche.sql
-- Taux de frais propre à un marchand.
--
-- Le taux du projet (tondo_project_config.frais_marchand) vaut pour tous. Mais
-- un partenariat se négocie : 1 % pour un hôpital, davantage ailleurs. Cette
-- colonne porte l'exception.
--
-- **NULL = appliquer le taux du projet.** Pas de booléen « utiliser le taux
-- par défaut » à côté : deux champs pour une seule information finiraient par
-- se contredire — un drapeau à faux avec une valeur nulle, ou l'inverse — et
-- plus personne ne saurait lequel fait foi. Ici la question ne se pose pas :
-- il y a une valeur, ou il n'y en a pas.
--
-- La colonne accepte **0** : un partenaire peut être exonéré. C'est bien
-- distinct de NULL, qui veut dire « comme tout le monde » — si le taux du
-- projet passe à 5 %, l'exonéré reste à 0 et les autres suivent.
--
-- ⚠️ Comme le taux du projet, ce taux n'est pas encore APPLIQUÉ au
-- décaissement : il est stocké et lisible. Brancher le calcul est une
-- décision distincte, que Daniel a réservée.
--
-- ⚠️ TEST (`tondo_`). Miroir de `037_tonji_frais_marchand_fiche.sql`.
-- IDEMPOTENT. À jouer dans le SQL Editor Supabase.
-- ============================================================================

ALTER TABLE public.tondo_marchands
  ADD COLUMN IF NOT EXISTS frais_taux numeric(6,4);

COMMENT ON COLUMN public.tondo_marchands.frais_taux IS
  'Taux de frais négocié avec CE marchand (0.01 = 1 %). NULL = appliquer le taux du projet. 0 = exonéré, ce qui n''est PAS la même chose que NULL.';

DO $$
BEGIN
  -- Un taux, pas un montant. La borne haute écarte la faute de frappe « 1 »
  -- pour 1 %, qui prélèverait la totalité du paiement.
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_marchands_frais_taux_check') THEN
    ALTER TABLE public.tondo_marchands ADD CONSTRAINT tondo_marchands_frais_taux_check
      CHECK (frais_taux IS NULL OR (frais_taux >= 0 AND frais_taux <= 0.25));
  END IF;
END $$;


-- ============================================================================
-- FIN TEST_037_tondo_frais_marchand_fiche.sql
-- ============================================================================
