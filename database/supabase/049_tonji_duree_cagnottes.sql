-- ============================================================================
-- 049 — Durée maximale d'une cagnotte
--
-- Une cagnotte ouverte ne doit pas vivre indéfiniment. On lui donne une durée
-- maximale, exprimée EN JOURS et réglable depuis le back-office. À l'échéance,
-- les fonds sont rapatriés sur le numéro de retrait et la cagnotte est
-- clôturée — le mécanisme de reversement existe déjà, c'est son mode « date ».
--
-- TROIS CHANGEMENTS.
--
-- 1. `project_config.duree_max_cagnotte_jours` — le réglage. NULL = AUCUNE
--    limite, et c'est le défaut : poser cette colonne ne doit fermer aucune
--    cagnotte tant que personne n'a décidé d'une durée.
--
-- 2. `cagnottes.date_echeance` — l'échéance effective, matérialisée.
--
--    Elle est DÉRIVÉE, pas figée : recalculée à chaque passage comme
--    `date_creation + durée`. Passer le réglage de 6 mois à 1 an rallonge donc
--    tout le parc au passage suivant, sans toucher une ligne à la main. C'est
--    ce qui en fait une période glissante.
--
--    Une exception, et une seule : si le calcul tombe DANS LE PASSÉ — une
--    cagnotte déjà plus vieille que la durée qu'on vient de fixer — on ne la
--    clôture pas le soir même. On lui pose une échéance à trente jours, une
--    fois, pour que la série de rappels ait le temps de se dérouler. Cette
--    échéance-là est COLLANTE : la recalculer chaque jour la repousserait
--    indéfiniment et la cagnotte ne se fermerait jamais.
--
-- 3. `tonji_rappels_echeance` — ce qui a déjà été envoyé.
--
--    Cinq paliers avant la clôture : 30, 14, 7, 3 et 1 jour. L'unicité est
--    tenue par un INDEX, pas par une lecture préalable : deux exécutions
--    simultanées du cron enverraient sinon le même rappel deux fois. La même
--    leçon que la double-cotisation du 2026-08-31.
--
-- Idempotent : rejouable sans dommage.
-- ============================================================================

-- ── 1. Le réglage ───────────────────────────────────────────────────────────
ALTER TABLE public.tonji_project_config
  ADD COLUMN IF NOT EXISTS duree_max_cagnotte_jours smallint;

COMMENT ON COLUMN public.tonji_project_config.duree_max_cagnotte_jours IS
  'Durée de vie maximale d''une cagnotte ouverte, en jours, comptée depuis sa création. NULL = aucune limite. Ne s''applique pas aux tontines, dont la durée tient au nombre de participants et à la périodicité.';

-- ── 2. L'échéance effective ─────────────────────────────────────────────────
ALTER TABLE public.tonji_cagnottes
  ADD COLUMN IF NOT EXISTS date_echeance date;

COMMENT ON COLUMN public.tonji_cagnottes.date_echeance IS
  'Date de clôture automatique, dérivée de date_creation + duree_max_cagnotte_jours et recalculée à chaque passage. Figée uniquement quand le calcul tombe dans le passé : trente jours de grâce, posés une fois, pour laisser les rappels se dérouler.';

CREATE INDEX IF NOT EXISTS tonji_cagnottes_date_echeance_idx
  ON public.tonji_cagnottes (date_echeance)
  WHERE date_echeance IS NOT NULL;

-- ── 3. Les rappels déjà envoyés ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.tonji_rappels_echeance (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id   uuid NOT NULL,
  cagnotte_id  uuid NOT NULL,
  -- Jours restants au moment de l'envoi : 30, 14, 7, 3 ou 1.
  palier       smallint NOT NULL,
  canaux       text,
  date_creation timestamptz NOT NULL DEFAULT now()
);

-- La garde d'idempotence. Deux exécutions concurrentes du cron tentent la même
-- insertion ; l'une passe, l'autre est refusée par l'index, et l'organisateur
-- ne reçoit qu'un seul message.
CREATE UNIQUE INDEX IF NOT EXISTS tonji_rappels_echeance_unique
  ON public.tonji_rappels_echeance (cagnotte_id, palier);

CREATE INDEX IF NOT EXISTS tonji_rappels_echeance_cagnotte_idx
  ON public.tonji_rappels_echeance (cagnotte_id);

GRANT SELECT, INSERT, UPDATE, DELETE ON public.tonji_rappels_echeance TO service_role;

-- Contrôle.
SELECT duree_max_cagnotte_jours FROM public.tonji_project_config LIMIT 5;
