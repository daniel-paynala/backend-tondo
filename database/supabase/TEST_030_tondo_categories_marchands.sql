-- ============================================================================
-- TEST_030_tondo_categories_marchands.sql
-- La catégorie d'un marchand devient une liste gérable, plus un texte libre.
--
-- 029 posait `categorie` en texte saisi à la main. À trois marchands cela va,
-- à trois cents on obtient « Santé », « santé » et « Pharmacie » pour la même
-- chose, et plus aucun regroupement fiable dans le dashboard.
--
-- La catégorie devient donc une table, administrée dans Paramètres comme les
-- supports de retrait : on en ajoute, on en renomme, on en désactive. La fiche
-- marchand n'en garde qu'une référence.
--
-- Une catégorie utilisée ne se supprime pas (clé étrangère en RESTRICT) : on la
-- désactive, ce qui la retire des formulaires sans toucher aux fiches
-- existantes. La catégorie reste facultative sur un marchand.
--
-- Le texte déjà saisi est repris : chaque valeur distincte devient une
-- catégorie, les fiches y sont rattachées, puis l'ancienne colonne disparaît.
--
-- ⚠️ TEST (`tondo_`). Miroir de `030_tonji_categories_marchands.sql`, à jouer sur la base de recette.
-- IDEMPOTENT. À jouer dans le SQL Editor Supabase.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1. tondo_categories_marchands
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.tondo_categories_marchands (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id  uuid NOT NULL REFERENCES public.projects(id) ON DELETE RESTRICT,

  libelle     varchar(40) NOT NULL,
  description varchar(160),
  -- Désactiver retire la catégorie des formulaires sans rien casser.
  actif       boolean NOT NULL DEFAULT true,

  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE public.tondo_categories_marchands IS
  'Catégories de marchands (santé, école, restauration…), administrées dans Paramètres. Une catégorie utilisée ne se supprime pas : on la désactive.';

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_categories_marchands_libelle_check') THEN
    ALTER TABLE public.tondo_categories_marchands ADD CONSTRAINT tondo_categories_marchands_libelle_check
      CHECK (length(btrim(libelle)) >= 2);
  END IF;
END $$;

-- Unicité insensible à la casse : c'est précisément le doublon qu'on veut éviter.
CREATE UNIQUE INDEX IF NOT EXISTS tondo_categories_marchands_libelle_uidx
  ON public.tondo_categories_marchands (project_id, lower(libelle));

DROP TRIGGER IF EXISTS trg_tondo_categories_marchands_updated_at ON public.tondo_categories_marchands;
CREATE TRIGGER trg_tondo_categories_marchands_updated_at
  BEFORE UPDATE ON public.tondo_categories_marchands
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

ALTER TABLE public.tondo_categories_marchands ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "tondo_categories_marchands_same_project" ON public.tondo_categories_marchands;
CREATE POLICY "tondo_categories_marchands_same_project" ON public.tondo_categories_marchands
  FOR ALL USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());


-- ----------------------------------------------------------------------------
-- 2. tondo_marchands.categorie_id
-- ----------------------------------------------------------------------------
ALTER TABLE public.tondo_marchands
  ADD COLUMN IF NOT EXISTS categorie_id uuid;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_marchands_categorie_fk') THEN
    ALTER TABLE public.tondo_marchands ADD CONSTRAINT tondo_marchands_categorie_fk
      FOREIGN KEY (categorie_id) REFERENCES public.tondo_categories_marchands(id) ON DELETE RESTRICT;
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS tondo_marchands_categorie_idx
  ON public.tondo_marchands (categorie_id)
  WHERE categorie_id IS NOT NULL;


-- ----------------------------------------------------------------------------
-- 3. Reprise du texte déjà saisi, puis retrait de l'ancienne colonne
--
-- Le bloc entier ne s'exécute que si la colonne existe encore : rejouer le
-- script après coup ne fait rien.
-- ----------------------------------------------------------------------------
DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = 'public' AND table_name = 'tondo_marchands' AND column_name = 'categorie'
  ) THEN
    -- Une catégorie par valeur distincte, projet par projet.
    INSERT INTO public.tondo_categories_marchands (project_id, libelle)
    SELECT DISTINCT m.project_id, btrim(m.categorie)
      FROM public.tondo_marchands m
     WHERE m.categorie IS NOT NULL AND btrim(m.categorie) <> ''
    ON CONFLICT DO NOTHING;

    UPDATE public.tondo_marchands m
       SET categorie_id = c.id
      FROM public.tondo_categories_marchands c
     WHERE c.project_id = m.project_id
       AND lower(c.libelle) = lower(btrim(m.categorie))
       AND m.categorie_id IS NULL;

    ALTER TABLE public.tondo_marchands DROP COLUMN categorie;
  END IF;
END $$;


-- ----------------------------------------------------------------------------
-- 4. Catégories de départ
--
-- Insérées une seule fois par projet, et seulement si la table est vide : on
-- évite de faire réapparaître une catégorie que l'admin a supprimée.
-- ----------------------------------------------------------------------------
INSERT INTO public.tondo_categories_marchands (project_id, libelle)
SELECT p.id, v.libelle
  FROM public.projects p
 CROSS JOIN (VALUES
    ('Commerce'),
    ('Santé'),
    ('Éducation'),
    ('Restauration'),
    ('Transport'),
    ('Services'),
    ('Événementiel')
 ) AS v(libelle)
 WHERE NOT EXISTS (
   SELECT 1 FROM public.tondo_categories_marchands c WHERE c.project_id = p.id
 )
ON CONFLICT DO NOTHING;


-- ----------------------------------------------------------------------------
-- 5. Droits — mêmes règles que la table des marchands.
-- ----------------------------------------------------------------------------
GRANT ALL ON public.tondo_categories_marchands TO service_role;


-- ============================================================================
-- FIN TEST_030_tondo_categories_marchands.sql
-- ============================================================================
