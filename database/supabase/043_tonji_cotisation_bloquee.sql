-- ============================================================================
-- 043 — Verrou de collecte par cagnotte (PRODUCTION, préfixe tonji_)
--
-- Symétrique de `transfert_bloque` et `paiement_marchand_bloque`, qui ferment
-- les SORTIES d'une cagnotte. Il manquait le pendant à l'entrée : aucun moyen
-- de suspendre les cotisations d'une cagnotte précise sans la clôturer.
--
-- Les leviers existants ne couvraient pas ce cas :
--   – suspendre une association coupe la collecte de TOUTES ses cagnottes ;
--   – clôturer la cagnotte est définitif et visible du cotisant ;
--   – la modération ne concerne que les cagnottes publiques.
--
-- `false` par défaut et NOT NULL : une cagnotte collecte, sauf décision
-- explicite. Un NULL laisserait la question ouverte à chaque lecture.
--
-- Idempotent : rejouable sans dommage.
-- ============================================================================

ALTER TABLE public.tonji_cagnottes
  ADD COLUMN IF NOT EXISTS cotisation_bloquee boolean NOT NULL DEFAULT false;

COMMENT ON COLUMN public.tonji_cagnottes.cotisation_bloquee IS
  'Verrou administratif des cotisations pour CETTE cagnotte. Le verrou global du type de compte s''y ajoute (règle OU).';

-- ── Verrou GLOBAL, par type de compte ───────────────────────────────────────
-- Même support que `sorties_bloquees` : du JSON dans la config d'opérateur.
-- Pas de valeur par défaut en base — l'absence vaut « rien n'est bloqué », et
-- le modèle le résout (TondoProjectConfig::COLLECTES_OUVERTES). Une ligne de
-- config déjà écrite n'a donc rien à rattraper.
ALTER TABLE public.tonji_project_config
  ADD COLUMN IF NOT EXISTS cotisations_bloquees json;

COMMENT ON COLUMN public.tonji_project_config.cotisations_bloquees IS
  'Verrou global des cotisations par type de compte : {"particulier": false, "association": false}. NULL = rien de bloqué.';
