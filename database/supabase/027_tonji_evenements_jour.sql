-- ============================================================================
-- 027_tonji_evenements_jour.sql
-- Agrégats quotidiens de la télémétrie produit.
--
-- Le dashboard lit CETTE table, jamais `tonji_evenements`. À la cible de
-- fréquentation, compter des millions de lignes brutes à chaque affichage
-- rendrait la page inutilisable — et le coût grandit chaque jour, alors que
-- l'agrégat, lui, reste stable.
--
-- Rétention : les lignes brutes sont purgées à 90 jours, ces agrégats sont
-- conservés. C'est ce qui permet de comparer un entonnoir à celui d'il y a un
-- an sans garder un an d'événements unitaires.
--
-- `plateforme` vaut '' plutôt que NULL : en Postgres, deux NULL ne sont pas
-- égaux, et l'index unique ne dédoublonnerait pas les lignes sans plateforme.
--
-- ⚠️ PROD (`tonji_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.tonji_evenements_jour (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    project_id   uuid NOT NULL REFERENCES public.projects(id) ON DELETE RESTRICT,
    jour         date NOT NULL,
    nom          text NOT NULL,
    canal        text NOT NULL DEFAULT 'app',
    plateforme   text NOT NULL DEFAULT '',
    compte                int NOT NULL DEFAULT 0,
    utilisateurs_uniques  int NOT NULL DEFAULT 0,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);

-- Clé de l'agrégat : permet le recalcul par UPSERT. Le job repasse sur une
-- fenêtre glissante, car l'app bufferise et peut remonter hier aujourd'hui.
CREATE UNIQUE INDEX IF NOT EXISTS tonji_evenements_jour_cle_idx
  ON public.tonji_evenements_jour (project_id, jour, nom, canal, plateforme);

-- Lecture du dashboard : une série sur une période.
CREATE INDEX IF NOT EXISTS tonji_evenements_jour_serie_idx
  ON public.tonji_evenements_jour (project_id, nom, jour DESC);

ALTER TABLE public.tonji_evenements_jour ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "tonji_evenements_jour_same_project" ON public.tonji_evenements_jour;
CREATE POLICY "tonji_evenements_jour_same_project" ON public.tonji_evenements_jour
  FOR ALL
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

GRANT SELECT ON public.tonji_evenements_jour TO authenticated;
GRANT ALL    ON public.tonji_evenements_jour TO service_role;

COMMENT ON TABLE public.tonji_evenements_jour IS
  'Agrégats quotidiens de télémétrie. Lus par le dashboard ; les lignes brutes sont purgées à 90 jours.';

-- ============================================================================
-- FIN 027_tonji_evenements_jour.sql
-- ============================================================================
