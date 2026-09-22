-- ============================================================================
-- 029_tonji_marchands.sql
-- Paiement de marchands Airtel Money depuis une cagnotte.
--
-- Jusqu'ici, le transfert d'une cagnotte partait toujours vers le numéro de
-- retrait du bénéficiaire, un particulier. Le besoin nouveau est qu'un client
-- puisse envoyer ce solde vers un numéro que Tonji a enregistré comme
-- marchand : régler une facture, payer une école, un traiteur, un magasin.
--
-- Le décaissement reste rigoureusement le même appel Airtel : même cagnotte
-- débitée, même ligne dans `payout`. Ce qui change est le destinataire, et on
-- veut pouvoir isoler ces sorties dans le dashboard. D'où deux colonnes sur
-- `payout` (`type_beneficiaire`, `marchand_id`), sur le modèle exact de ce que
-- 028 a fait pour les espèces avec `canal` et `agent_id`.
--
-- Un marchand n'est pas un compte : c'est une fiche de destination, avec le
-- numéro Airtel qui reçoit, le nom commercial affiché au client, et le nom du
-- titulaire renvoyé par le KYC opérateur. Le type Paynala (`particulier` /
-- `entreprise`) est conservé sur la fiche car il pilote le routage B2C ou B2B
-- de l'appel `disburse` : un marchand Airtel est normalement une entreprise.
--
-- Une fiche marchand ne peut pas être supprimée si elle porte des paiements :
-- la clé étrangère est en ON DELETE RESTRICT, comme pour les agents de retrait.
-- Pour retirer un marchand du parcours client, on le désactive (`actif`).
--
-- Ce script recrée aussi la vue `tonji_transactions_unified`, qui depuis 028
-- masquait à tort le canal des sorties : elle forçait `NULL` pour les payout,
-- si bien que le dashboard ne pouvait pas distinguer un retrait en espèces
-- d'un transfert Mobile Money. La vue expose désormais le canal réel, l'agent,
-- le type de bénéficiaire, le marchand et son nom.
--
-- ⚠️ PROD (`tonji_`). En TEST, jouer `TEST_029_tondo_marchands.sql`.
-- IDEMPOTENT. À jouer dans le SQL Editor Supabase.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1. tonji_marchands — fiches des destinations marchandes
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.tonji_marchands (
  id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id            uuid NOT NULL REFERENCES public.projects(id) ON DELETE RESTRICT,

  -- Nom commercial : c'est ce que le client voit au moment de choisir.
  nom                   varchar(120) NOT NULL,
  -- Numéro Airtel Money qui encaisse, au format E.164 comme payout.numero_tel.
  numero_tel            varchar(16)  NOT NULL,
  -- Nom du titulaire renvoyé par le KYC Airtel : sert de contrôle visuel avant
  -- d'enregistrer la fiche, puis de preuve si un paiement est contesté.
  titulaire             varchar(120),
  titulaire_verifie_at  timestamptz,
  -- Pilote le routage de l'appel disburse : 'entreprise' => B2B, 'particulier' => B2C.
  type_paynala          varchar(12)  NOT NULL DEFAULT 'entreprise',

  categorie             varchar(40),
  ville                 varchar(60),
  contact_nom           varchar(120),
  contact_tel           varchar(16),
  contact_email         varchar(160),
  notes                 text,

  -- Désactiver plutôt que supprimer : la fiche sort du parcours client mais
  -- l'historique des paiements reste lisible.
  actif                 boolean NOT NULL DEFAULT true,

  created_at            timestamptz NOT NULL DEFAULT now(),
  updated_at            timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE public.tonji_marchands IS
  'Destinations marchandes d''un transfert de cagnotte : numéro Airtel Money enregistré par Tonji, avec le nom commercial affiché au client. Ce n''est pas un compte utilisateur.';
COMMENT ON COLUMN public.tonji_marchands.numero_tel IS
  'Numéro Airtel Money du marchand, format E.164 (+241XXXXXXXX). Unique par projet.';
COMMENT ON COLUMN public.tonji_marchands.type_paynala IS
  'Type transmis à l''API Paynala : entreprise => disburse type B2B, particulier => B2C.';

DO $$
BEGIN
  -- Format du numéro : mêmes règles que les numéros de retrait.
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_marchands_numero_check') THEN
    ALTER TABLE public.tonji_marchands ADD CONSTRAINT tonji_marchands_numero_check
      CHECK (numero_tel ~ '^\+241[0-9]{8,9}$');
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_marchands_type_paynala_check') THEN
    ALTER TABLE public.tonji_marchands ADD CONSTRAINT tonji_marchands_type_paynala_check
      CHECK (type_paynala IN ('particulier', 'entreprise'));
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_marchands_nom_check') THEN
    ALTER TABLE public.tonji_marchands ADD CONSTRAINT tonji_marchands_nom_check
      CHECK (length(btrim(nom)) >= 2);
  END IF;
END $$;

-- Un même numéro ne peut désigner qu'un seul marchand dans un projet donné.
CREATE UNIQUE INDEX IF NOT EXISTS tonji_marchands_numero_uidx
  ON public.tonji_marchands (project_id, numero_tel);

-- Liste du dashboard et sélection côté client : toujours filtrées par projet.
CREATE INDEX IF NOT EXISTS tonji_marchands_projet_actif_idx
  ON public.tonji_marchands (project_id, actif, nom);

DROP TRIGGER IF EXISTS trg_tonji_marchands_updated_at ON public.tonji_marchands;
CREATE TRIGGER trg_tonji_marchands_updated_at
  BEFORE UPDATE ON public.tonji_marchands
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

ALTER TABLE public.tonji_marchands ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "tonji_marchands_same_project" ON public.tonji_marchands;
CREATE POLICY "tonji_marchands_same_project" ON public.tonji_marchands
  FOR ALL USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());


-- ----------------------------------------------------------------------------
-- 2. tonji_payout — rattachement d'une sortie à un marchand
--
-- `type_beneficiaire` reste à 'particulier' pour tout l'historique : c'est ce
-- qu'étaient les transferts jusqu'ici. Le couple colonne + contrainte interdit
-- qu'une ligne se dise marchande sans fiche, ou l'inverse.
-- ----------------------------------------------------------------------------
ALTER TABLE public.tonji_payout
  ADD COLUMN IF NOT EXISTS type_beneficiaire varchar(20) NOT NULL DEFAULT 'particulier';

ALTER TABLE public.tonji_payout
  ADD COLUMN IF NOT EXISTS marchand_id uuid;

COMMENT ON COLUMN public.tonji_payout.type_beneficiaire IS
  'particulier = numéro de retrait du bénéficiaire (cas historique) ; marchand = numéro enregistré dans tonji_marchands.';

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_payout_type_beneficiaire_check') THEN
    ALTER TABLE public.tonji_payout ADD CONSTRAINT tonji_payout_type_beneficiaire_check
      CHECK (type_beneficiaire IN ('particulier', 'marchand'));
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_payout_marchand_fk') THEN
    ALTER TABLE public.tonji_payout ADD CONSTRAINT tonji_payout_marchand_fk
      FOREIGN KEY (marchand_id) REFERENCES public.tonji_marchands(id) ON DELETE RESTRICT;
  END IF;

  -- Les deux informations disent la même chose : elles ne peuvent pas diverger.
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_payout_marchand_coherence_check') THEN
    ALTER TABLE public.tonji_payout ADD CONSTRAINT tonji_payout_marchand_coherence_check
      CHECK ((type_beneficiaire = 'marchand') = (marchand_id IS NOT NULL));
  END IF;

  -- Un marchand encaisse par Mobile Money : jamais par la caisse d'un agent.
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tonji_payout_marchand_canal_check') THEN
    ALTER TABLE public.tonji_payout ADD CONSTRAINT tonji_payout_marchand_canal_check
      CHECK (marchand_id IS NULL OR canal = 'mobile_money');
  END IF;
END $$;

-- Relevé par marchand, du plus récent au plus ancien.
CREATE INDEX IF NOT EXISTS tonji_payout_marchand_idx
  ON public.tonji_payout (marchand_id, date_creation DESC)
  WHERE marchand_id IS NOT NULL;


-- ----------------------------------------------------------------------------
-- 3. Vue unifiée du dashboard
--
-- Deux corrections dans la même passe :
--   * le canal des payout, jusqu'ici forcé à NULL, devient le canal réel
--     (028 avait ajouté la colonne sans recréer la vue) ;
--   * quatre colonnes ajoutées EN QUEUE, seule position permise par
--     CREATE OR REPLACE VIEW : agent, type de bénéficiaire, marchand, et nom
--     du marchand pour éviter une jointure supplémentaire côté dashboard.
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW public.tonji_transactions_unified AS
 SELECT tonji_payin.id,
    'payin'::text AS type,
    tonji_payin.project_id,
    tonji_payin.cagnotte_id,
    tonji_payin.user_id,
    tonji_payin.trans_id,
    tonji_payin.operateur_id,
    tonji_payin.numero_tel,
    tonji_payin.montant,
    tonji_payin.statut,
    tonji_payin.request,
    tonji_payin.response,
    tonji_payin.date_creation,
    tonji_payin.created_at,
    tonji_payin.updated_at,
    tonji_payin.canal::varchar AS canal,
    tonji_payin.commentaire,
    NULL::uuid    AS agent_id,
    NULL::varchar AS type_beneficiaire,
    NULL::uuid    AS marchand_id,
    NULL::varchar AS marchand_nom
   FROM public.tonji_payin
UNION ALL
 SELECT p.id,
    'payout'::text AS type,
    p.project_id,
    p.cagnotte_id,
    p.user_id,
    p.trans_id,
    p.operateur_id,
    p.numero_tel,
    p.montant,
    p.statut,
    p.request,
    p.response,
    p.date_creation,
    p.created_at,
    p.updated_at,
    p.canal::varchar AS canal,
    NULL::text AS commentaire,
    p.agent_id,
    p.type_beneficiaire::varchar AS type_beneficiaire,
    p.marchand_id,
    m.nom::varchar AS marchand_nom
   FROM public.tonji_payout p
   LEFT JOIN public.tonji_marchands m ON m.id = p.marchand_id
UNION ALL
 SELECT tonji_payout_paynala.id,
    'payout_paynala'::text AS type,
    tonji_payout_paynala.project_id,
    tonji_payout_paynala.cagnotte_id,
    NULL::uuid AS user_id,
    tonji_payout_paynala.trans_id,
    tonji_payout_paynala.operateur_id,
    NULL::text AS numero_tel,
    tonji_payout_paynala.montant,
    tonji_payout_paynala.statut,
    tonji_payout_paynala.request,
    tonji_payout_paynala.response,
    tonji_payout_paynala.date_creation,
    tonji_payout_paynala.created_at,
    tonji_payout_paynala.updated_at,
    NULL::varchar AS canal,
    NULL::text AS commentaire,
    NULL::uuid    AS agent_id,
    NULL::varchar AS type_beneficiaire,
    NULL::uuid    AS marchand_id,
    NULL::varchar AS marchand_nom
   FROM public.tonji_payout_paynala;

COMMENT ON VIEW public.tonji_transactions_unified IS
  'Union des 3 tables transactionnelles. Lecture seule. Filtrer par project_id et type côté requête. Depuis 029 : canal réel des sorties, agent de retrait, type de bénéficiaire et marchand.';


-- ----------------------------------------------------------------------------
-- 4. Droits
--
-- Le backend et le dashboard lisent en service_role. Rien n'est ouvert à
-- `authenticated` sur la table des marchands : elle porte des coordonnées de
-- contact, et aucune application cliente n'y accède en direct.
-- ----------------------------------------------------------------------------
GRANT ALL ON public.tonji_marchands TO service_role;

GRANT SELECT ON public.tonji_transactions_unified TO authenticated;
GRANT SELECT ON public.tonji_transactions_unified TO service_role;


-- ============================================================================
-- FIN 029_tonji_marchands.sql
-- ============================================================================
