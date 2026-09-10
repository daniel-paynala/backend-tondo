-- ============================================================================
-- 026_tonji_evenements.sql
-- Télémétrie produit : ce que les utilisateurs font dans l'app.
--
-- Distincte de `tonji_logs`, qui journalise les actions ADMIN (acteur_admin_id,
-- rôles super_admin/systeme) à des fins d'audit. Ici il s'agit du comportement
-- des utilisateurs finaux, à des fins d'analyse produit.
--
-- ⚠️ AUCUN CONTENU SAISI n'entre dans cette table. Pas de numéro, pas de
-- montant exact (des tranches uniquement), pas de commentaire de cotisation.
-- Seule exception assumée : le terme cherché dans l'annuaire public, qui n'est
-- pas une donnée personnelle et répond à une question produit directe.
--
-- `id` est généré par le CLIENT et sert de clé d'idempotence : un lot renvoyé
-- après un timeout ne doit pas doubler les compteurs.
--
-- `occurred_at` est l'horodatage CLIENT (l'app bufferise et peut être hors
-- ligne) ; `created_at` est celui du serveur. L'écart entre les deux mesure la
-- latence de remontée.
--
-- ⚠️ PROD (`tonji_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.tonji_evenements (
    id           uuid PRIMARY KEY,
    project_id   uuid NOT NULL REFERENCES public.projects(id) ON DELETE RESTRICT,
    -- Nullable : un événement peut précéder la création du compte (inscription).
    -- ON DELETE SET NULL : la suppression d'un compte anonymise sa télémétrie
    -- sans détruire les compteurs.
    user_id      uuid REFERENCES public.users(id) ON DELETE SET NULL,
    -- Généré au lancement de l'app : permet de reconstituer un parcours.
    session_id   text NOT NULL,
    nom          text NOT NULL,
    canal        text NOT NULL DEFAULT 'app',
    plateforme   text,
    version_app  text,
    contexte     jsonb NOT NULL DEFAULT '{}'::jsonb,
    occurred_at  timestamptz NOT NULL,
    created_at   timestamptz NOT NULL DEFAULT now()
);

-- Lecture principale du dashboard : un événement sur une période.
CREATE INDEX IF NOT EXISTS tonji_evenements_nom_date_idx
  ON public.tonji_evenements (project_id, nom, occurred_at DESC);

-- Reconstitution d'un parcours complet.
CREATE INDEX IF NOT EXISTS tonji_evenements_session_idx
  ON public.tonji_evenements (session_id, occurred_at);

-- Purge des données brutes au-delà de la rétention (90 jours).
CREATE INDEX IF NOT EXISTS tonji_evenements_created_idx
  ON public.tonji_evenements (created_at);

ALTER TABLE public.tonji_evenements ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "tonji_evenements_same_project" ON public.tonji_evenements;
CREATE POLICY "tonji_evenements_same_project" ON public.tonji_evenements
  FOR ALL
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

GRANT SELECT, INSERT ON public.tonji_evenements TO authenticated;
GRANT ALL    ON public.tonji_evenements TO service_role;

COMMENT ON TABLE public.tonji_evenements IS
  'Télémétrie produit (comportement utilisateur). Aucun contenu saisi : ni numéro, ni montant exact, ni commentaire.';

-- ============================================================================
-- FIN 026_tonji_evenements.sql
-- ============================================================================
