-- ============================================================================
-- TEST_035_tondo_frais_marchand.sql
-- Frais du paiement marchand, configurables.
--
-- Le modèle se précise : Tonji ne prélève plus rien sur les cotisations
-- (`commission_paynala` est à 0), et prend sa part sur le **paiement d'un
-- marchand** depuis une cagnotte. Ce taux manquait — c'était le seul des
-- quatre postes à n'avoir aucune place en base :
--
--   1. cotisation ............ `commission_paynala` (à 0 aujourd'hui)
--   2. paiement marchand ..... CETTE COLONNE
--   3. transfert vers un numéro `frais_retrait` (matrice, à 0 aujourd'hui)
--   4. retrait en espèces .... `tranches` (3 % puis forfait, barème opérateur)
--
-- Un taux et non un booléen : « si on gère je mettrai 3, si on ne gère pas je
-- mettrai 0 » — la valeur pilote l'affichage ET, le jour venu, le calcul.
-- Mettre 0 revient à ne rien prélever, sans qu'aucune ligne de code ne change.
--
-- **Défaut 0.03.** C'est la règle annoncée, et un défaut à 0 ferait silence sur
-- un prélèvement que le produit annonce. Le dashboard permet de le ramener à 0.
--
-- ⚠️ Ce script N'APPLIQUE PAS encore le taux au décaissement : il le stocke et
-- le rend lisible. Toucher au calcul de l'argent est une décision distincte.
--
-- ⚠️ TEST (`tondo_`). Miroir de `035_tonji_frais_marchand.sql`.
-- IDEMPOTENT. À jouer dans le SQL Editor Supabase.
-- ============================================================================

ALTER TABLE public.tondo_project_config
  ADD COLUMN IF NOT EXISTS frais_marchand numeric(6,4) NOT NULL DEFAULT 0.03;

COMMENT ON COLUMN public.tondo_project_config.frais_marchand IS
  'Taux prélevé par Tonji sur un paiement marchand depuis une cagnotte (0.03 = 3 %). 0 = aucun prélèvement. Pilote l''affichage dans l''app et les conditions d''utilisation.';

DO $$
BEGIN
  -- Un taux, pas un montant : entre 0 et 1. La borne haute écarte la faute de
  -- frappe « 3 » pour 3 %, qui prélèverait trois fois le montant transféré.
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_project_config_frais_marchand_check') THEN
    ALTER TABLE public.tondo_project_config ADD CONSTRAINT tondo_project_config_frais_marchand_check
      CHECK (frais_marchand >= 0 AND frais_marchand <= 1);
  END IF;
END $$;


-- ============================================================================
-- FIN TEST_035_tondo_frais_marchand.sql
-- ============================================================================
