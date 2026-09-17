-- ============================================================================
-- 028_tonji_retrait_agents.sql
-- Retrait en espèces : supports, partenaires, agents.
--
-- Un agent remet des BILLETS. Un virement erroné se conteste ; des espèces
-- remises à tort sont perdues. Trois entités, parce qu'elles vivent et
-- changent à des rythmes différents :
--
--   support de retrait     le CANAL — TPE, guichet, boutique, USSD…
--   partenaire de retrait  l'ÉTABLISSEMENT — Ecobank, UBA, Sengab…
--                          C'est lui que Tonji rembourse, et c'est son système
--                          qui présente la clé d'API.
--   agent de retrait       la PERSONNE qui opère, rattachée à un partenaire ET
--                          à un support. S'identifie par un identifiant et un
--                          PIN à 4 chiffres.
--
-- Identifiant de l'agent : sigle partenaire + sigle support + numéro sur 3
-- chiffres minimum — « ECKTPE020 », 20e agent TPE d'Ecobank. Il sert à la
-- connexion ET d'identifiant public (SMS d'autorisation, comptoir).
--
-- Remplace l'ancien 028_tonji_agents.sql (code A-1042, type en dur, clé d'API
-- portée par l'agent), qui n'a été joué qu'en TEST.
--
-- ⚠️ PROD (`tonji_`). En TEST, jouer TEST_028_tondo_retrait_agents.sql.
-- IDEMPOTENT.
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────
-- 1. Supports de retrait
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.tonji_supports_retrait (
    id              uuid        DEFAULT gen_random_uuid() NOT NULL,
    project_id      uuid        NOT NULL,
    libelle         text        NOT NULL,       -- « Terminal de paiement »
    -- Trois lettres EXACTEMENT : l'identifiant d'un agent accole deux sigles,
    -- et seule une longueur fixe les rend séparables sans ambiguïté.
    sigle           varchar(3)  NOT NULL,       -- « TPE »
    description     text,
    -- Un TPE ou un guichet a une adresse ; l'USSD non. Pilote l'obligation de
    -- renseigner ville et quartier à la création d'un agent.
    necessite_lieu  boolean     NOT NULL DEFAULT true,
    -- Désactiver un support bloque d'office tous ses agents, sans réécrire
    -- leurs statuts : c'est vérifié à chaque connexion.
    actif           boolean     NOT NULL DEFAULT true,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);


-- ─────────────────────────────────────────────────────────────────────────
-- 2. Partenaires de retrait
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.tonji_partenaires_retrait (
    id                     uuid        DEFAULT gen_random_uuid() NOT NULL,
    project_id             uuid        NOT NULL,
    nom                    text        NOT NULL,   -- « Ecobank Gabon »
    sigle                  varchar(3)  NOT NULL,   -- « ECK »
    actif                  boolean     NOT NULL DEFAULT true,

    contact_nom            text,
    contact_telephone      text,
    contact_email          text,
    -- Où et comment Tonji rembourse le partenaire des espèces avancées par
    -- ses agents. Le règlement se fait au niveau de l'établissement, jamais
    -- agent par agent.
    reglement_coordonnees  text,

    -- ── Clé d'API du système du partenaire ────────────────────────────────
    -- Première moitié de l'authentification d'un terminal : le système du
    -- partenaire présente sa clé, puis l'agent son PIN. Un PIN volé ne sert
    -- donc à rien hors d'un terminal du partenaire.
    -- SHA-256 et non bcrypt : il faut RETROUVER le partenaire à partir de la
    -- clé présentée, ce qu'un hachage salé interdit. La clé est un aléa de
    -- 24 octets, pas un mot de passe : aucun dictionnaire à lui opposer.
    cle_api_hash           text,
    cle_api_apercu         varchar(8),
    cle_api_creee_at       timestamptz,

    created_at             timestamptz NOT NULL DEFAULT now(),
    updated_at             timestamptz NOT NULL DEFAULT now()
);


-- ─────────────────────────────────────────────────────────────────────────
-- 3. Compteurs de numérotation des agents
-- ─────────────────────────────────────────────────────────────────────────
-- Un compteur par couple partenaire × support. Incrémenté par un UPSERT
-- atomique, jamais par « compter les agents puis +1 » : deux créations
-- simultanées obtiendraient le même numéro. Et un numéro n'est jamais
-- réattribué — il figure dans des SMS envoyés et dans les journaux.
CREATE TABLE IF NOT EXISTS public.tonji_agents_compteurs (
    project_id      uuid        NOT NULL,
    partenaire_id   uuid        NOT NULL,
    support_id      uuid        NOT NULL,
    dernier_numero  integer     NOT NULL DEFAULT 0,
    updated_at      timestamptz NOT NULL DEFAULT now()
);


-- ─────────────────────────────────────────────────────────────────────────
-- 4. Agents de retrait
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.tonji_agents (
    id                        uuid        DEFAULT gen_random_uuid() NOT NULL,
    project_id                uuid        NOT NULL,

    -- Ni l'un ni l'autre ne se modifient après création : l'identifiant les
    -- encode. Un agent qui change de partenaire ou de support est un NOUVEL
    -- agent.
    partenaire_id             uuid        NOT NULL,
    support_id                uuid        NOT NULL,
    numero                    integer     NOT NULL,

    -- Figé à la création. Si le sigle du partenaire change plus tard,
    -- l'identifiant reste le même : il est dans les SMS et les journaux.
    identifiant               varchar(20) NOT NULL,   -- « ECKTPE020 »

    nom                       text        NOT NULL,
    telephone                 text,
    ville                     text,
    quartier                  text,

    statut                    varchar(20) NOT NULL DEFAULT 'actif',
    motif_suspension          text,

    -- ── PIN ────────────────────────────────────────────────────────────────
    -- Haché avec bcrypt, à l'inverse des clés d'API : 4 chiffres ne font que
    -- 10 000 combinaisons, il faut un hachage lent et salé. On retrouve
    -- l'agent par son identifiant, pas par son PIN, donc le sel ne gêne pas.
    pin_hash                  text        NOT NULL,
    -- Vrai à la création et après chaque réinitialisation : le PIN transmis
    -- par l'admin doit être remplacé à la première connexion.
    pin_doit_changer          boolean     NOT NULL DEFAULT true,
    pin_tentatives_echouees   smallint    NOT NULL DEFAULT 0,
    -- Posé après trop d'échecs ; seule une réinitialisation par un admin le
    -- lève. Sans verrouillage, 10 000 essais suffisent.
    pin_verrouille_at         timestamptz,
    pin_modifie_at            timestamptz,

    -- ── Plafonds ───────────────────────────────────────────────────────────
    -- Par opération, et cumulé par jour : le second borne ce qu'un agent
    -- compromis peut sortir en une journée, quel que soit le nombre de
    -- retraits enchaînés.
    plafond_operation_fcfa    bigint      NOT NULL DEFAULT 200000,
    plafond_journalier_fcfa   bigint      NOT NULL DEFAULT 1000000,

    derniere_connexion_at     timestamptz,
    derniere_activite_at      timestamptz,

    created_at                timestamptz NOT NULL DEFAULT now(),
    updated_at                timestamptz NOT NULL DEFAULT now()
);


-- ─────────────────────────────────────────────────────────────────────────
-- 5. Clés, contraintes, index
-- ─────────────────────────────────────────────────────────────────────────
DO $$
BEGIN
  -- Clés primaires
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_supports_retrait_pkey') THEN
    ALTER TABLE public.tonji_supports_retrait ADD CONSTRAINT tonji_supports_retrait_pkey PRIMARY KEY (id);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_partenaires_retrait_pkey') THEN
    ALTER TABLE public.tonji_partenaires_retrait ADD CONSTRAINT tonji_partenaires_retrait_pkey PRIMARY KEY (id);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_agents_compteurs_pkey') THEN
    ALTER TABLE public.tonji_agents_compteurs ADD CONSTRAINT tonji_agents_compteurs_pkey PRIMARY KEY (partenaire_id, support_id);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_agents_pkey') THEN
    ALTER TABLE public.tonji_agents ADD CONSTRAINT tonji_agents_pkey PRIMARY KEY (id);
  END IF;

  -- Rattachement au projet
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_supports_retrait_project_fk') THEN
    ALTER TABLE public.tonji_supports_retrait ADD CONSTRAINT tonji_supports_retrait_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_partenaires_retrait_project_fk') THEN
    ALTER TABLE public.tonji_partenaires_retrait ADD CONSTRAINT tonji_partenaires_retrait_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_agents_project_fk') THEN
    ALTER TABLE public.tonji_agents ADD CONSTRAINT tonji_agents_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;
  END IF;

  -- Un partenaire ou un support ne se supprime pas tant qu'un agent y est
  -- rattaché : RESTRICT. Il se désactive.
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_agents_partenaire_fk') THEN
    ALTER TABLE public.tonji_agents ADD CONSTRAINT tonji_agents_partenaire_fk
      FOREIGN KEY (partenaire_id) REFERENCES public.tonji_partenaires_retrait(id) ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_agents_support_fk') THEN
    ALTER TABLE public.tonji_agents ADD CONSTRAINT tonji_agents_support_fk
      FOREIGN KEY (support_id) REFERENCES public.tonji_supports_retrait(id) ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_agents_compteurs_partenaire_fk') THEN
    ALTER TABLE public.tonji_agents_compteurs ADD CONSTRAINT tonji_agents_compteurs_partenaire_fk
      FOREIGN KEY (partenaire_id) REFERENCES public.tonji_partenaires_retrait(id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_agents_compteurs_support_fk') THEN
    ALTER TABLE public.tonji_agents_compteurs ADD CONSTRAINT tonji_agents_compteurs_support_fk
      FOREIGN KEY (support_id) REFERENCES public.tonji_supports_retrait(id) ON DELETE CASCADE;
  END IF;

  -- Formats
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_supports_retrait_sigle_check') THEN
    ALTER TABLE public.tonji_supports_retrait ADD CONSTRAINT tonji_supports_retrait_sigle_check
      CHECK (sigle ~ '^[A-Z]{3}$');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_partenaires_retrait_sigle_check') THEN
    ALTER TABLE public.tonji_partenaires_retrait ADD CONSTRAINT tonji_partenaires_retrait_sigle_check
      CHECK (sigle ~ '^[A-Z]{3}$');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_agents_identifiant_check') THEN
    ALTER TABLE public.tonji_agents ADD CONSTRAINT tonji_agents_identifiant_check
      CHECK (identifiant ~ '^[A-Z]{6}[0-9]{3,}$');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_agents_statut_check') THEN
    ALTER TABLE public.tonji_agents ADD CONSTRAINT tonji_agents_statut_check
      CHECK (statut IN ('actif', 'suspendu'));
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_agents_numero_check') THEN
    ALTER TABLE public.tonji_agents ADD CONSTRAINT tonji_agents_numero_check
      CHECK (numero > 0);
  END IF;
  -- Un plafond journalier inférieur au plafond par opération rendrait
  -- celui-ci inatteignable sans que rien ne le signale.
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_agents_plafonds_check') THEN
    ALTER TABLE public.tonji_agents ADD CONSTRAINT tonji_agents_plafonds_check
      CHECK (plafond_operation_fcfa > 0 AND plafond_journalier_fcfa >= plafond_operation_fcfa);
  END IF;
END $$;

-- Sigles uniques dans le projet : deux partenaires « ECK » produiraient les
-- mêmes identifiants d'agents.
CREATE UNIQUE INDEX IF NOT EXISTS tonji_supports_retrait_sigle_uidx
  ON public.tonji_supports_retrait (project_id, sigle);
CREATE UNIQUE INDEX IF NOT EXISTS tonji_supports_retrait_libelle_uidx
  ON public.tonji_supports_retrait (project_id, lower(libelle));
CREATE UNIQUE INDEX IF NOT EXISTS tonji_partenaires_retrait_sigle_uidx
  ON public.tonji_partenaires_retrait (project_id, sigle);
CREATE UNIQUE INDEX IF NOT EXISTS tonji_partenaires_retrait_nom_uidx
  ON public.tonji_partenaires_retrait (project_id, lower(nom));
-- Partiel : plusieurs partenaires peuvent n'avoir encore aucune clé.
CREATE UNIQUE INDEX IF NOT EXISTS tonji_partenaires_retrait_cle_uidx
  ON public.tonji_partenaires_retrait (cle_api_hash) WHERE cle_api_hash IS NOT NULL;

-- Connexion d'un agent : une lecture par identifiant.
CREATE UNIQUE INDEX IF NOT EXISTS tonji_agents_identifiant_uidx
  ON public.tonji_agents (project_id, identifiant);
-- Filet de sécurité derrière le compteur : même un bug ne peut produire
-- deux agents portant le même numéro.
CREATE UNIQUE INDEX IF NOT EXISTS tonji_agents_numero_uidx
  ON public.tonji_agents (partenaire_id, support_id, numero);
CREATE INDEX IF NOT EXISTS tonji_agents_projet_idx
  ON public.tonji_agents (project_id, created_at DESC);


-- ─────────────────────────────────────────────────────────────────────────
-- 6. Maintien de updated_at
-- ─────────────────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_tonji_supports_retrait_updated_at ON public.tonji_supports_retrait;
CREATE TRIGGER trg_tonji_supports_retrait_updated_at
  BEFORE UPDATE ON public.tonji_supports_retrait
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

DROP TRIGGER IF EXISTS trg_tonji_partenaires_retrait_updated_at ON public.tonji_partenaires_retrait;
CREATE TRIGGER trg_tonji_partenaires_retrait_updated_at
  BEFORE UPDATE ON public.tonji_partenaires_retrait
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

DROP TRIGGER IF EXISTS trg_tonji_agents_updated_at ON public.tonji_agents;
CREATE TRIGGER trg_tonji_agents_updated_at
  BEFORE UPDATE ON public.tonji_agents
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


-- ─────────────────────────────────────────────────────────────────────────
-- 7. Cloisonnement et droits
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE public.tonji_supports_retrait    ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tonji_partenaires_retrait ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tonji_agents_compteurs    ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tonji_agents              ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "tonji_supports_retrait_same_project" ON public.tonji_supports_retrait;
CREATE POLICY "tonji_supports_retrait_same_project" ON public.tonji_supports_retrait
  FOR ALL USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

DROP POLICY IF EXISTS "tonji_partenaires_retrait_same_project" ON public.tonji_partenaires_retrait;
CREATE POLICY "tonji_partenaires_retrait_same_project" ON public.tonji_partenaires_retrait
  FOR ALL USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

DROP POLICY IF EXISTS "tonji_agents_compteurs_same_project" ON public.tonji_agents_compteurs;
CREATE POLICY "tonji_agents_compteurs_same_project" ON public.tonji_agents_compteurs
  FOR ALL USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

DROP POLICY IF EXISTS "tonji_agents_same_project" ON public.tonji_agents;
CREATE POLICY "tonji_agents_same_project" ON public.tonji_agents
  FOR ALL USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

-- service_role uniquement. AUCUN droit pour `authenticated` : ces tables
-- portent des empreintes de PIN et de clés d'API, qu'aucune application
-- cliente ne doit pouvoir lire, même filtrées par RLS.
GRANT ALL ON public.tonji_supports_retrait    TO service_role;
GRANT ALL ON public.tonji_partenaires_retrait TO service_role;
GRANT ALL ON public.tonji_agents_compteurs    TO service_role;
GRANT ALL ON public.tonji_agents              TO service_role;
