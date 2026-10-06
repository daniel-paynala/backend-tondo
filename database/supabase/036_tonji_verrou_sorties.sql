-- ============================================================================
-- 036_tonji_verrou_sorties.sql
-- Verrouillage des sorties d'argent : transfert et paiement marchand.
--
-- Deux niveaux, qui se cumulent :
--
--   * PAR CAGNOTTE — deux drapeaux sur la collecte. Sert au cas particulier :
--     un soupçon sur une collecte précise, une contestation en cours.
--   * PAR TYPE DE COMPTE — une matrice sur la config projet. Sert à fermer un
--     canal d'un coup, pour tous les particuliers ou toutes les associations.
--
-- **La règle est un OU, jamais un ET** : une sortie est bloquée si le verrou
-- global OU le verrou de la cagnotte l'interdit. L'inverse permettrait à un
-- réglage de cagnotte de rouvrir ce qu'une décision globale vient de fermer,
-- ce qui viderait le verrou global de son sens au moment où il sert le plus.
--
-- Les deux actions sont distinctes et non un drapeau unique : fermer le
-- paiement marchand pendant qu'on enquête sur une enseigne ne doit pas
-- empêcher un bénéficiaire de récupérer son propre argent.
--
-- ⚠️ Le verrou ne vaut QUE parce que le serveur refuse l'opération. Masquer un
-- bouton ne protège rien — l'application n'est pas un endroit sûr.
--
-- ⚠️ PROD (`tonji_`). En TEST, jouer `TEST_036_tondo_verrou_sorties.sql`.
-- IDEMPOTENT. À jouer dans le SQL Editor Supabase.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1. Verrous par cagnotte
-- ----------------------------------------------------------------------------
ALTER TABLE public.tonji_cagnottes
  ADD COLUMN IF NOT EXISTS transfert_bloque boolean NOT NULL DEFAULT false;

ALTER TABLE public.tonji_cagnottes
  ADD COLUMN IF NOT EXISTS paiement_marchand_bloque boolean NOT NULL DEFAULT false;

COMMENT ON COLUMN public.tonji_cagnottes.transfert_bloque IS
  'Interdit le transfert du solde vers un numéro pour CETTE collecte. Se cumule avec le verrou global du type de compte : une sortie est bloquée si l''un des deux l''interdit.';
COMMENT ON COLUMN public.tonji_cagnottes.paiement_marchand_bloque IS
  'Interdit le paiement d''un commerce depuis CETTE collecte. Indépendant du transfert : enquêter sur une enseigne ne doit pas empêcher un bénéficiaire de récupérer son argent.';

-- Retrouver les collectes verrouillées : rares par construction, l'index
-- partiel ne couvre qu'elles et reste quasiment vide en régime normal.
CREATE INDEX IF NOT EXISTS tonji_cagnottes_sorties_bloquees_idx
  ON public.tonji_cagnottes (project_id)
  WHERE transfert_bloque OR paiement_marchand_bloque;


-- ----------------------------------------------------------------------------
-- 2. Verrou global, par type de compte
-- ----------------------------------------------------------------------------
ALTER TABLE public.tonji_project_config
  ADD COLUMN IF NOT EXISTS sorties_bloquees json NOT NULL
  DEFAULT '{"particulier":{"transfert":false,"marchand":false},"association":{"transfert":false,"marchand":false}}'::json;

COMMENT ON COLUMN public.tonji_project_config.sorties_bloquees IS
  'Verrous globaux des sorties d''argent, par type de compte puis par action. Se cumulent avec les drapeaux de chaque collecte : une sortie est bloquée si l''un des deux l''interdit.';


-- ============================================================================
-- FIN 036_tonji_verrou_sorties.sql
-- ============================================================================
