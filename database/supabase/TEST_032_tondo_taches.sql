-- ============================================================================
-- TEST_032_tondo_taches.sql
-- Trace du dernier passage de chaque tâche planifiée.
--
-- Le planificateur déclare huit tâches, dont les transferts de 18 h et les
-- retraits de tontine de 20 h. Rien ne disait jusqu'ici si elles s'étaient
-- exécutées. Une entrée crontab supprimée, un disque plein, un verrou resté
-- posé : le service s'arrête sans bruit, et on l'apprend quand un gérant
-- s'étonne de ne pas avoir reçu son argent.
--
-- Cette table est écrite par un écouteur des événements du planificateur, une
-- ligne par tâche, mise à jour à chaque passage. La sonde de santé compare
-- ensuite l'horodatage à la cadence attendue.
--
-- Table d'exploitation, pas de donnée métier : pas de project_id, RLS
-- désactivée, accès réservé au rôle de service — même choix que tondo_admins.
--
-- ⚠️ TEST (`tondo_`). Miroir de `032_tonji_taches.sql`.
-- IDEMPOTENT. À jouer dans le SQL Editor Supabase.
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.tondo_taches (
  -- La commande artisan sert de clé : une tâche, une ligne, mise à jour.
  commande            varchar(80) PRIMARY KEY,

  derniere_execution  timestamptz NOT NULL,
  duree_ms            integer,
  -- 'succes' | 'echec' — un échec garde l'horodatage, pour distinguer
  -- « la tâche ne tourne plus » de « la tâche tourne et échoue ».
  statut              varchar(10) NOT NULL DEFAULT 'succes',
  message             text,

  -- Compteurs cumulés : donnent une idée de la régularité sans historiser
  -- chaque passage, ce qui ferait des dizaines de milliers de lignes par jour
  -- pour la tâche qui tourne toutes les cinq secondes.
  passages            bigint NOT NULL DEFAULT 1,
  echecs              bigint NOT NULL DEFAULT 0,

  created_at          timestamptz NOT NULL DEFAULT now(),
  updated_at          timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE public.tondo_taches IS
  'Dernier passage de chaque tâche planifiée. Alimentée par l''écouteur du planificateur, lue par la sonde de santé.';
COMMENT ON COLUMN public.tondo_taches.passages IS
  'Nombre de passages depuis la création de la ligne : les passages ne sont pas historisés un par un.';

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_taches_statut_check') THEN
    ALTER TABLE public.tondo_taches ADD CONSTRAINT tondo_taches_statut_check
      CHECK (statut IN ('succes', 'echec'));
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS tondo_taches_execution_idx
  ON public.tondo_taches (derniere_execution DESC);

DROP TRIGGER IF EXISTS trg_tondo_taches_updated_at ON public.tondo_taches;
CREATE TRIGGER trg_tondo_taches_updated_at
  BEFORE UPDATE ON public.tondo_taches
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- Table d'exploitation : aucune application cliente ne la lit.
ALTER TABLE public.tondo_taches DISABLE ROW LEVEL SECURITY;
GRANT ALL ON public.tondo_taches TO service_role;

-- ============================================================================
-- FIN TEST_032_tondo_taches.sql
-- ============================================================================
