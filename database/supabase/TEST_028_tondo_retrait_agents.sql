-- ============================================================================
-- 028_tondo_retrait_agents.sql
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
--   retrait en espèces     l'OPÉRATION. Ancre des règles de suppression :
--                          tant qu'aucun retrait n'est passé par un agent ou un
--                          support, on peut le supprimer.
--
-- Identifiant de l'agent : sigle partenaire + sigle support + numéro sur 3
-- chiffres minimum — « ECKTPE020 », 20e agent TPE d'Ecobank. Il sert à la
-- connexion ET d'identifiant public (SMS d'autorisation, comptoir).
--
-- Remplace l'ancien 028_tonji_agents.sql (code A-1042, type en dur, clé d'API
-- portée par l'agent), qui n'a été joué qu'en TEST.
--
-- ⚠️ VARIANTE TEST (`tondo_`). Générée depuis 028_tonji_retrait_agents.sql —
-- modifier la SOURCE, jamais ce fichier. IDEMPOTENT.
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────
-- 1. Supports de retrait
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.tondo_supports_retrait (
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
CREATE TABLE IF NOT EXISTS public.tondo_partenaires_retrait (
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
CREATE TABLE IF NOT EXISTS public.tondo_agents_compteurs (
    project_id      uuid        NOT NULL,
    partenaire_id   uuid        NOT NULL,
    support_id      uuid        NOT NULL,
    dernier_numero  integer     NOT NULL DEFAULT 0,
    updated_at      timestamptz NOT NULL DEFAULT now()
);


-- ─────────────────────────────────────────────────────────────────────────
-- 4. Agents de retrait
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.tondo_agents (
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
-- 4 bis. Retraits en espèces (le dossier)
-- ─────────────────────────────────────────────────────────────────────────
-- Une ligne par demande de retrait, de la saisie au comptoir jusqu'à son
-- issue. Seul un retrait VALIDÉ fait sortir de l'argent : il écrit alors une
-- ligne dans le grand livre (payout), dans la même transaction que le débit
-- du solde. Une demande en attente, expirée, refusée ou annulée n'y laisse
-- aucune trace.
--
--   en_attente_code ──► valide     (code juste, solde et plafonds suffisants)
--                   ├─► refuse     (3 codes faux, solde ou plafond insuffisant)
--                   ├─► expire     (code non saisi à temps)
--                   └─► annule     (abandon, ou remplacée par une nouvelle demande)
CREATE TABLE IF NOT EXISTS public.tondo_retraits_especes (
    id               uuid        DEFAULT gen_random_uuid() NOT NULL,
    project_id       uuid        NOT NULL,

    -- TONJICASH + 9 caractères. Citée dans les SMS au titulaire, et reprise
    -- telle quelle comme trans_id du payout : un seul identifiant de la
    -- demande au grand livre.
    reference        varchar(30) NOT NULL,

    agent_id         uuid        NOT NULL,
    cagnotte_id      uuid        NOT NULL,
    montant_fcfa     bigint      NOT NULL,

    statut           varchar(20) NOT NULL DEFAULT 'en_attente_code',
    motif_refus      text,

    -- Code envoyé par SMS au numéro de retrait. Haché : même la base ne
    -- permet pas de valider un retrait à la place du titulaire.
    code_hash        text        NOT NULL,
    code_tentatives  smallint    NOT NULL DEFAULT 0,
    code_expire_at   timestamptz NOT NULL,

    -- Fournie par le terminal. Rejouer une demande avec la même clé renvoie
    -- le même dossier, jamais un second retrait — c'est ce qui rend sûre une
    -- nouvelle tentative après une coupure réseau.
    cle_idempotence  varchar(100) NOT NULL,

    -- Renseigné à la validation, et seulement à la validation.
    payout_id        uuid,
    -- Moment où le dossier a atteint son issue, quelle qu'elle soit.
    termine_at       timestamptz,

    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);


-- ─────────────────────────────────────────────────────────────────────────
-- 4 ter. Le grand livre : payout accueille les espèces
-- ─────────────────────────────────────────────────────────────────────────
-- Un retrait validé est une sortie d'argent comme une autre : il s'inscrit
-- dans payout, et la réconciliation (Σ payin − Σ payout = solde) comme
-- l'historique du gérant l'intègrent sans autre changement. Deux colonnes le
-- distinguent d'un transfert Mobile Money.
--
-- Valeur par défaut `mobile_money` : toutes les lignes existantes restent ce
-- qu'elles sont, sans réécriture.
ALTER TABLE public.tondo_payout ADD COLUMN IF NOT EXISTS canal    varchar(20) NOT NULL DEFAULT 'mobile_money';
-- L'agent qui a remis les billets. Vide pour un transfert Mobile Money.
ALTER TABLE public.tondo_payout ADD COLUMN IF NOT EXISTS agent_id uuid;


-- ─────────────────────────────────────────────────────────────────────────
-- 5. Clés, contraintes, index
-- ─────────────────────────────────────────────────────────────────────────
DO $$
BEGIN
  -- Clés primaires
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_supports_retrait_pkey') THEN
    ALTER TABLE public.tondo_supports_retrait ADD CONSTRAINT tondo_supports_retrait_pkey PRIMARY KEY (id);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_partenaires_retrait_pkey') THEN
    ALTER TABLE public.tondo_partenaires_retrait ADD CONSTRAINT tondo_partenaires_retrait_pkey PRIMARY KEY (id);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_agents_compteurs_pkey') THEN
    ALTER TABLE public.tondo_agents_compteurs ADD CONSTRAINT tondo_agents_compteurs_pkey PRIMARY KEY (partenaire_id, support_id);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_agents_pkey') THEN
    ALTER TABLE public.tondo_agents ADD CONSTRAINT tondo_agents_pkey PRIMARY KEY (id);
  END IF;

  -- Rattachement au projet
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_supports_retrait_project_fk') THEN
    ALTER TABLE public.tondo_supports_retrait ADD CONSTRAINT tondo_supports_retrait_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_partenaires_retrait_project_fk') THEN
    ALTER TABLE public.tondo_partenaires_retrait ADD CONSTRAINT tondo_partenaires_retrait_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_agents_project_fk') THEN
    ALTER TABLE public.tondo_agents ADD CONSTRAINT tondo_agents_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;
  END IF;

  -- Un partenaire ou un support ne se supprime pas tant qu'un agent y est
  -- rattaché : RESTRICT. Il se désactive.
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_agents_partenaire_fk') THEN
    ALTER TABLE public.tondo_agents ADD CONSTRAINT tondo_agents_partenaire_fk
      FOREIGN KEY (partenaire_id) REFERENCES public.tondo_partenaires_retrait(id) ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_agents_support_fk') THEN
    ALTER TABLE public.tondo_agents ADD CONSTRAINT tondo_agents_support_fk
      FOREIGN KEY (support_id) REFERENCES public.tondo_supports_retrait(id) ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_agents_compteurs_partenaire_fk') THEN
    ALTER TABLE public.tondo_agents_compteurs ADD CONSTRAINT tondo_agents_compteurs_partenaire_fk
      FOREIGN KEY (partenaire_id) REFERENCES public.tondo_partenaires_retrait(id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_agents_compteurs_support_fk') THEN
    ALTER TABLE public.tondo_agents_compteurs ADD CONSTRAINT tondo_agents_compteurs_support_fk
      FOREIGN KEY (support_id) REFERENCES public.tondo_supports_retrait(id) ON DELETE CASCADE;
  END IF;

  -- Formats
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_supports_retrait_sigle_check') THEN
    ALTER TABLE public.tondo_supports_retrait ADD CONSTRAINT tondo_supports_retrait_sigle_check
      CHECK (sigle ~ '^[A-Z]{3}$');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_partenaires_retrait_sigle_check') THEN
    ALTER TABLE public.tondo_partenaires_retrait ADD CONSTRAINT tondo_partenaires_retrait_sigle_check
      CHECK (sigle ~ '^[A-Z]{3}$');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_agents_identifiant_check') THEN
    ALTER TABLE public.tondo_agents ADD CONSTRAINT tondo_agents_identifiant_check
      CHECK (identifiant ~ '^[A-Z]{6}[0-9]{3,}$');
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_agents_statut_check') THEN
    ALTER TABLE public.tondo_agents ADD CONSTRAINT tondo_agents_statut_check
      CHECK (statut IN ('actif', 'suspendu'));
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_agents_numero_check') THEN
    ALTER TABLE public.tondo_agents ADD CONSTRAINT tondo_agents_numero_check
      CHECK (numero > 0);
  END IF;
  -- Un plafond journalier inférieur au plafond par opération rendrait
  -- celui-ci inatteignable sans que rien ne le signale.
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_agents_plafonds_check') THEN
    ALTER TABLE public.tondo_agents ADD CONSTRAINT tondo_agents_plafonds_check
      CHECK (plafond_operation_fcfa > 0 AND plafond_journalier_fcfa >= plafond_operation_fcfa);
  END IF;

  -- Retraits
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_retraits_especes_pkey') THEN
    ALTER TABLE public.tondo_retraits_especes ADD CONSTRAINT tondo_retraits_especes_pkey PRIMARY KEY (id);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_retraits_especes_project_fk') THEN
    ALTER TABLE public.tondo_retraits_especes ADD CONSTRAINT tondo_retraits_especes_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;
  END IF;
  -- RESTRICT partout : c'est le filet de sécurité derrière les contrôles du
  -- code. Ni l'agent, ni la cagnotte, ni la ligne du grand livre ne peuvent
  -- disparaître sous un dossier de retrait.
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_retraits_especes_agent_fk') THEN
    ALTER TABLE public.tondo_retraits_especes ADD CONSTRAINT tondo_retraits_especes_agent_fk
      FOREIGN KEY (agent_id) REFERENCES public.tondo_agents(id) ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_retraits_especes_cagnotte_fk') THEN
    ALTER TABLE public.tondo_retraits_especes ADD CONSTRAINT tondo_retraits_especes_cagnotte_fk
      FOREIGN KEY (cagnotte_id) REFERENCES public.tondo_cagnottes(id) ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_retraits_especes_payout_fk') THEN
    ALTER TABLE public.tondo_retraits_especes ADD CONSTRAINT tondo_retraits_especes_payout_fk
      FOREIGN KEY (payout_id) REFERENCES public.tondo_payout(id) ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_retraits_especes_montant_check') THEN
    ALTER TABLE public.tondo_retraits_especes ADD CONSTRAINT tondo_retraits_especes_montant_check
      CHECK (montant_fcfa > 0);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_retraits_especes_statut_check') THEN
    ALTER TABLE public.tondo_retraits_especes ADD CONSTRAINT tondo_retraits_especes_statut_check
      CHECK (statut IN ('en_attente_code', 'valide', 'refuse', 'expire', 'annule'));
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_retraits_especes_reference_check') THEN
    ALTER TABLE public.tondo_retraits_especes ADD CONSTRAINT tondo_retraits_especes_reference_check
      CHECK (reference ~ '^TONJICASH[A-Z0-9]{9}$');
  END IF;
  -- Un dossier est validé si et seulement s'il a écrit dans le grand livre :
  -- ni argent sorti sans dossier validé, ni dossier validé sans argent sorti.
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_retraits_especes_payout_check') THEN
    ALTER TABLE public.tondo_retraits_especes ADD CONSTRAINT tondo_retraits_especes_payout_check
      CHECK ((statut = 'valide') = (payout_id IS NOT NULL));
  END IF;

  -- Payout
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_payout_canal_check') THEN
    ALTER TABLE public.tondo_payout ADD CONSTRAINT tondo_payout_canal_check
      CHECK (canal IN ('mobile_money', 'especes'));
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_payout_agent_fk') THEN
    ALTER TABLE public.tondo_payout ADD CONSTRAINT tondo_payout_agent_fk
      FOREIGN KEY (agent_id) REFERENCES public.tondo_agents(id) ON DELETE RESTRICT;
  END IF;
  -- Espèces si et seulement si un agent est désigné : une sortie en espèces
  -- sans agent n'aurait personne à qui demander des comptes.
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_payout_canal_agent_check') THEN
    ALTER TABLE public.tondo_payout ADD CONSTRAINT tondo_payout_canal_agent_check
      CHECK ((canal = 'especes') = (agent_id IS NOT NULL));
  END IF;
END $$;

-- Sigles uniques dans le projet : deux partenaires « ECK » produiraient les
-- mêmes identifiants d'agents.
CREATE UNIQUE INDEX IF NOT EXISTS tondo_supports_retrait_sigle_uidx
  ON public.tondo_supports_retrait (project_id, sigle);
CREATE UNIQUE INDEX IF NOT EXISTS tondo_supports_retrait_libelle_uidx
  ON public.tondo_supports_retrait (project_id, lower(libelle));
CREATE UNIQUE INDEX IF NOT EXISTS tondo_partenaires_retrait_sigle_uidx
  ON public.tondo_partenaires_retrait (project_id, sigle);
CREATE UNIQUE INDEX IF NOT EXISTS tondo_partenaires_retrait_nom_uidx
  ON public.tondo_partenaires_retrait (project_id, lower(nom));
-- Partiel : plusieurs partenaires peuvent n'avoir encore aucune clé.
CREATE UNIQUE INDEX IF NOT EXISTS tondo_partenaires_retrait_cle_uidx
  ON public.tondo_partenaires_retrait (cle_api_hash) WHERE cle_api_hash IS NOT NULL;

-- Connexion d'un agent : une lecture par identifiant.
CREATE UNIQUE INDEX IF NOT EXISTS tondo_agents_identifiant_uidx
  ON public.tondo_agents (project_id, identifiant);
-- Filet de sécurité derrière le compteur : même un bug ne peut produire
-- deux agents portant le même numéro.
CREATE UNIQUE INDEX IF NOT EXISTS tondo_agents_numero_uidx
  ON public.tondo_agents (partenaire_id, support_id, numero);
CREATE INDEX IF NOT EXISTS tondo_agents_projet_idx
  ON public.tondo_agents (project_id, created_at DESC);

CREATE UNIQUE INDEX IF NOT EXISTS tondo_retraits_especes_reference_uidx
  ON public.tondo_retraits_especes (reference);
-- Une clé d'idempotence n'a de sens que pour le terminal qui l'a émise.
CREATE UNIQUE INDEX IF NOT EXISTS tondo_retraits_especes_idempotence_uidx
  ON public.tondo_retraits_especes (agent_id, cle_idempotence);
-- UNE seule demande en attente par cagnotte, garantie par la base : sans cela,
-- le titulaire recevrait deux codes pour deux montants et pourrait autoriser
-- le mauvais.
CREATE UNIQUE INDEX IF NOT EXISTS tondo_retraits_especes_attente_uidx
  ON public.tondo_retraits_especes (cagnotte_id) WHERE statut = 'en_attente_code';
-- Cumul journalier d'un agent, et contrôle « un retrait est-il passé par lui ».
CREATE INDEX IF NOT EXISTS tondo_retraits_especes_agent_idx
  ON public.tondo_retraits_especes (agent_id, statut, termine_at);
CREATE INDEX IF NOT EXISTS tondo_retraits_especes_cagnotte_idx
  ON public.tondo_retraits_especes (cagnotte_id, created_at DESC);
-- Relevé d'un partenaire : les sorties en espèces de ses agents.
CREATE INDEX IF NOT EXISTS tondo_payout_agent_idx
  ON public.tondo_payout (agent_id, date_creation) WHERE agent_id IS NOT NULL;


-- ─────────────────────────────────────────────────────────────────────────
-- 6. Maintien de updated_at
-- ─────────────────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_tondo_supports_retrait_updated_at ON public.tondo_supports_retrait;
CREATE TRIGGER trg_tondo_supports_retrait_updated_at
  BEFORE UPDATE ON public.tondo_supports_retrait
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

DROP TRIGGER IF EXISTS trg_tondo_partenaires_retrait_updated_at ON public.tondo_partenaires_retrait;
CREATE TRIGGER trg_tondo_partenaires_retrait_updated_at
  BEFORE UPDATE ON public.tondo_partenaires_retrait
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

DROP TRIGGER IF EXISTS trg_tondo_agents_updated_at ON public.tondo_agents;
CREATE TRIGGER trg_tondo_agents_updated_at
  BEFORE UPDATE ON public.tondo_agents
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

DROP TRIGGER IF EXISTS trg_tondo_retraits_especes_updated_at ON public.tondo_retraits_especes;
CREATE TRIGGER trg_tondo_retraits_especes_updated_at
  BEFORE UPDATE ON public.tondo_retraits_especes
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


-- ─────────────────────────────────────────────────────────────────────────
-- 7. Cloisonnement et droits
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE public.tondo_supports_retrait    ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tondo_partenaires_retrait ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tondo_agents_compteurs    ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tondo_agents              ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.tondo_retraits_especes    ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "tondo_supports_retrait_same_project" ON public.tondo_supports_retrait;
CREATE POLICY "tondo_supports_retrait_same_project" ON public.tondo_supports_retrait
  FOR ALL USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

DROP POLICY IF EXISTS "tondo_partenaires_retrait_same_project" ON public.tondo_partenaires_retrait;
CREATE POLICY "tondo_partenaires_retrait_same_project" ON public.tondo_partenaires_retrait
  FOR ALL USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

DROP POLICY IF EXISTS "tondo_agents_compteurs_same_project" ON public.tondo_agents_compteurs;
CREATE POLICY "tondo_agents_compteurs_same_project" ON public.tondo_agents_compteurs
  FOR ALL USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

DROP POLICY IF EXISTS "tondo_agents_same_project" ON public.tondo_agents;
CREATE POLICY "tondo_agents_same_project" ON public.tondo_agents
  FOR ALL USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

DROP POLICY IF EXISTS "tondo_retraits_especes_same_project" ON public.tondo_retraits_especes;
CREATE POLICY "tondo_retraits_especes_same_project" ON public.tondo_retraits_especes
  FOR ALL USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

-- service_role uniquement. AUCUN droit pour `authenticated` : ces tables
-- portent des empreintes de PIN et de clés d'API, qu'aucune application
-- cliente ne doit pouvoir lire, même filtrées par RLS.
GRANT ALL ON public.tondo_supports_retrait    TO service_role;
GRANT ALL ON public.tondo_partenaires_retrait TO service_role;
GRANT ALL ON public.tondo_agents_compteurs    TO service_role;
GRANT ALL ON public.tondo_agents              TO service_role;
GRANT ALL ON public.tondo_retraits_especes    TO service_role;
