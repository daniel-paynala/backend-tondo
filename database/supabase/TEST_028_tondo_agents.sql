-- ============================================================================
-- 028_tondo_agents.sql
-- Socle « agent » : les points partenaires qui remettent des espèces.
--
-- Un agent est un TIERS qui avance sa caisse et remet des billets au
-- bénéficiaire d'une cagnotte. Ce n'est pas un décaissement Mobile Money de
-- plus : l'argent sort physiquement, et un billet remis à tort ne se conteste
-- pas. D'où trois choses inscrites dans le schéma plutôt que dans le code :
--
--   1. `code` — identifiant COURT, lisible à voix haute, affiché au comptoir.
--      Il part dans le SMS d'autorisation (« … chez Agent Mbolo (A-1042) »).
--      Un UUID n'aurait servi qu'à la traçabilité interne ; ici le
--      bénéficiaire doit pouvoir confronter ce qu'il lit sur son téléphone à
--      ce qu'il voit au mur.
--   2. `statut` — un terminal compromis doit cesser de fonctionner
--      immédiatement, sans attendre un déploiement.
--   3. `plafond_retrait_fcfa` — un agent nouveau démarre bas. Sans plafond
--      par agent, la seule limite serait celle de la plateforme, la même pour
--      un partenaire éprouvé et pour un inconnu.
--
-- `type` est volontairement une contrainte CHECK et non un type enum : ajouter
-- une valeur se fait par un simple bloc DO, sans ALTER TYPE ni verrou de
-- table. Voir la section 5 pour la marche à suivre.
--
-- ⚠️ VARIANTE TEST (`tondo_`). Générée depuis 028_tonji_agents.sql —
-- modifier la SOURCE, jamais ce fichier. IDEMPOTENT.
-- ============================================================================

-- ─────────────────────────────────────────────────────────────────────────
-- 1. Table
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.tondo_agents (
    id          uuid        DEFAULT gen_random_uuid() NOT NULL,
    project_id  uuid        NOT NULL,

    -- Identifiant public du point. Court, sans ambiguïté à l'oral ni à la
    -- lecture — d'où l'exclusion des caractères qui se confondent (voir la
    -- contrainte de format en section 3).
    code        varchar(12) NOT NULL,

    -- Nom commercial affiché au bénéficiaire, dans le SMS et dans l'app.
    nom         text        NOT NULL,

    -- Nature du point, qui détermine par quel moyen il joint l'API.
    --   tpe     : terminal de paiement chez un commerçant
    --   guichet : comptoir partenaire sans terminal (web ou application)
    type        varchar(20) NOT NULL DEFAULT 'tpe',

    -- actif | suspendu. La suspension prend effet à l'appel suivant.
    statut      varchar(20) NOT NULL DEFAULT 'actif',
    motif_suspension text,

    -- Localisation, pour orienter le bénéficiaire vers le point le plus proche.
    ville       text,
    quartier    text,
    -- Contact de l'exploitant — jamais affiché au bénéficiaire.
    telephone   text,

    -- ── Authentification du point ──────────────────────────────────────────
    -- On stocke SHA-256 de la clé, pas la clé. Un hachage salé type bcrypt
    -- serait inutilisable ici : il faudrait parcourir toute la table à chaque
    -- appel pour retrouver l'agent. SHA-256 non salé convient parce que la
    -- clé est un aléa de 32 octets, pas un mot de passe choisi par un humain :
    -- il n'y a pas de dictionnaire à lui opposer.
    cle_api_hash    text,
    -- Quatre derniers caractères, pour que l'exploitant reconnaisse SA clé
    -- dans le backoffice sans qu'on ait à la conserver en clair.
    cle_api_apercu  varchar(8),
    cle_api_creee_at timestamptz,

    -- ── Garde-fous ────────────────────────────────────────────────────────
    -- Plafond par opération de retrait, propre à cet agent.
    plafond_retrait_fcfa bigint NOT NULL DEFAULT 200000,

    derniere_activite_at timestamptz,

    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);

-- ─────────────────────────────────────────────────────────────────────────
-- 2. Clés
-- ─────────────────────────────────────────────────────────────────────────
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conname = 'tondo_agents_pkey' AND conrelid = 'public.tondo_agents'::regclass
  ) THEN
    ALTER TABLE public.tondo_agents ADD CONSTRAINT tondo_agents_pkey PRIMARY KEY (id);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conname = 'tondo_agents_project_fk' AND conrelid = 'public.tondo_agents'::regclass
  ) THEN
    ALTER TABLE public.tondo_agents
      ADD CONSTRAINT tondo_agents_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;
  END IF;
END $$;

-- ─────────────────────────────────────────────────────────────────────────
-- 3. Contraintes de valeur
-- ─────────────────────────────────────────────────────────────────────────
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_agents_type_check'
  ) THEN
    ALTER TABLE public.tondo_agents
      ADD CONSTRAINT tondo_agents_type_check
      CHECK (type IN ('tpe', 'guichet'));
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_agents_statut_check'
  ) THEN
    ALTER TABLE public.tondo_agents
      ADD CONSTRAINT tondo_agents_statut_check
      CHECK (statut IN ('actif', 'suspendu'));
  END IF;

  -- Format du code : une lettre, un tiret, quatre chiffres — « A-1042 ».
  -- Uniquement des chiffres après le tiret : une lettre s'y prêterait aux
  -- confusions O/0 et I/1 au moment de lire le code à voix haute.
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_agents_code_format_check'
  ) THEN
    ALTER TABLE public.tondo_agents
      ADD CONSTRAINT tondo_agents_code_format_check
      CHECK (code ~ '^[A-Z]-[0-9]{4}$');
  END IF;

  -- Un plafond nul ou négatif désactiverait l'agent par accident, sans que
  -- son statut le dise.
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_agents_plafond_check'
  ) THEN
    ALTER TABLE public.tondo_agents
      ADD CONSTRAINT tondo_agents_plafond_check
      CHECK (plafond_retrait_fcfa > 0);
  END IF;
END $$;

-- ─────────────────────────────────────────────────────────────────────────
-- 4. Index
-- ─────────────────────────────────────────────────────────────────────────
-- Le code identifie l'agent auprès du public : il doit être unique DANS le
-- projet, et c'est par lui qu'on retrouve un point depuis le backoffice.
CREATE UNIQUE INDEX IF NOT EXISTS tondo_agents_code_uidx
  ON public.tondo_agents (project_id, code);

-- Authentification : une lecture par appel d'API, donc un index unique.
-- Partiel, parce que la colonne est nulle tant qu'aucune clé n'a été émise —
-- et deux agents sans clé ne doivent pas entrer en collision.
CREATE UNIQUE INDEX IF NOT EXISTS tondo_agents_cle_uidx
  ON public.tondo_agents (cle_api_hash)
  WHERE cle_api_hash IS NOT NULL;

-- Listing du backoffice : les agents d'un projet, les plus récents d'abord.
CREATE INDEX IF NOT EXISTS tondo_agents_projet_idx
  ON public.tondo_agents (project_id, created_at DESC);

-- ─────────────────────────────────────────────────────────────────────────
-- 5. Maintien de updated_at
-- ─────────────────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_tondo_agents_updated_at ON public.tondo_agents;
CREATE TRIGGER trg_tondo_agents_updated_at
  BEFORE UPDATE ON public.tondo_agents
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- ─────────────────────────────────────────────────────────────────────────
-- 6. Cloisonnement par projet
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE public.tondo_agents ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "tondo_agents_same_project" ON public.tondo_agents;
CREATE POLICY "tondo_agents_same_project" ON public.tondo_agents
  FOR ALL
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

-- ============================================================================
--  AJOUTER UN TYPE D'AGENT
--
--  Remplacer la contrainte, dans une nouvelle migration — jamais en modifiant
--  celle-ci, qui a déjà été jouée :
--
--    ALTER TABLE public.tondo_agents DROP CONSTRAINT tondo_agents_type_check;
--    ALTER TABLE public.tondo_agents
--      ADD CONSTRAINT tondo_agents_type_check
--      CHECK (type IN ('tpe', 'guichet', 'nouveau_type'));
--
--  Et déclarer la valeur dans App\Support\TypesAgent, qui fait foi côté code.
-- ============================================================================
