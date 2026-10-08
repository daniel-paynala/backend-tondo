-- ============================================================================
-- 039_tonji_plafond_franchise_retrait.sql
-- Le modèle économique cesse d'être écrit en dur dans l'app.
--
-- ── Le problème ─────────────────────────────────────────────────────────────
--
-- Le document « Proposition de modèle économique » remis à Airtel tarife le
-- reversement à « 2 %, plafonné à 5 000 F, gratuit sous 50 000 F ». La config
-- projet sait exprimer le TAUX (matrice `frais_retrait`, par type de collecte
-- et de compte), mais ni le plafond ni la franchise. Ces deux valeurs étaient
-- donc figées dans le code de l'app mobile — changer un prix aurait demandé de
-- publier une version sur les stores, là où tout le reste se règle au
-- dashboard.
--
-- ── Ce que ce script ajoute ─────────────────────────────────────────────────
--
--   plafond_frais_retrait  plafond du prélèvement, en FCFA. 0 = aucun plafond.
--   franchise_retrait      en dessous de ce montant, le reversement est
--                          gratuit, en FCFA. 0 = aucune franchise.
--
-- Zéro comme défaut, et non 5 000 / 50 000 : une colonne ajoutée ne doit pas
-- changer d'elle-même un prix affiché. Les valeurs du document sont posées
-- explicitement par l'UPDATE final, qui est le seul endroit de ce fichier à
-- toucher à une donnée existante.
--
-- Idempotent : rejouable sans effet de bord.
-- ============================================================================

ALTER TABLE public.tonji_project_config
  ADD COLUMN IF NOT EXISTS plafond_frais_retrait integer NOT NULL DEFAULT 0;

ALTER TABLE public.tonji_project_config
  ADD COLUMN IF NOT EXISTS franchise_retrait integer NOT NULL DEFAULT 0;

COMMENT ON COLUMN public.tonji_project_config.plafond_frais_retrait IS
  'Plafond du prélèvement sur un reversement, en FCFA. 0 = aucun plafond.';

COMMENT ON COLUMN public.tonji_project_config.franchise_retrait IS
  'Reversement gratuit sous ce montant, en FCFA. 0 = aucune franchise.';

-- Un prélèvement ne peut pas être négatif, et un plafond à zéro signifie
-- « aucun plafond » — pas « prélèvement nul ». La contrainte dit la borne
-- basse, le code dit le sens de zéro.
ALTER TABLE public.tonji_project_config
  DROP CONSTRAINT IF EXISTS tonji_project_config_plafond_frais_retrait_positif;
ALTER TABLE public.tonji_project_config
  ADD CONSTRAINT tonji_project_config_plafond_frais_retrait_positif
  CHECK (plafond_frais_retrait >= 0);

ALTER TABLE public.tonji_project_config
  DROP CONSTRAINT IF EXISTS tonji_project_config_franchise_retrait_positif;
ALTER TABLE public.tonji_project_config
  ADD CONSTRAINT tonji_project_config_franchise_retrait_positif
  CHECK (franchise_retrait >= 0);

-- ── Valeurs du document (décision Daniel, 2026-10-07) ───────────────────────
--
-- À NE JOUER QUE SI le modèle « 2 % plafonnés à 5 000 F, gratuits sous
-- 50 000 F » doit s'appliquer. Les deux valeurs se règlent ensuite au
-- dashboard, Paramètres → Plafonds & frais.
UPDATE public.tonji_project_config
   SET plafond_frais_retrait = 5000,
       franchise_retrait     = 50000,
       updated_at            = now()
 WHERE plafond_frais_retrait = 0
   AND franchise_retrait     = 0;
