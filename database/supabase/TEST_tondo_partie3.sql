-- ============================================================================
--  TEST_tondo_partie3.sql  —  PARTIE 3/3 : Plafonds, push, CGU, telemetrie
--  Schéma de la base de TEST, préfixe `tondo_`.
--
--  Généré le 2026-09-11 depuis database/supabase/*.sql,
--  avec `tonji_` (prod) réécrit en `tondo_` (test).
--
--  ⚠️ EXÉCUTER LES TROIS PARTIES DANS L'ORDRE : 1, puis 2, puis 3.
--     Le découpage existe parce qu'un fichier unique dépassait le délai
--     d'exécution de l'éditeur SQL Supabase.
--
--  ⚠️ NE PAS ÉDITER À LA MAIN : artefact. Corriger le fichier numéroté
--     d'origine, puis régénérer.
--
--  IDEMPOTENT : rejouable sans effet sur une base déjà à jour.
--
--  Fichiers de cette partie :
--    018_tonji_plafonds_cagnotte.sql
--    019_tonji_frais_retrait.sql
--    020_tonji_device_tokens.sql
--    021_tonji_plafond.sql
--    022_tonji_commentaire_cotisation.sql
--    023_cgu_acceptation.sql
--    024_associations_sans_dossier.sql
--    025_backfill_type_compte.sql
--    026_tonji_evenements.sql
--    027_tonji_evenements_jour.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────
-- ▼ 018_tonji_plafonds_cagnotte.sql
-- ─────────────────────────────────────────────────────────

-- ============================================================================
-- 018_tondo_plafonds_cagnotte.sql
-- Plafonds TOTAUX de collecte d'une cagnotte (éditables par les super_admin).
--
--   - plafond_cagnotte_particulier : 2 500 000 FCFA (cagnottes de particuliers)
--   - plafond_cagnotte_association : 10 000 000 FCFA (cagnottes d'associations,
--     loi n°35/62 ; sert de défaut, le plafond par organisation reste dans
--     tondo_organisations.plafond_fcfa)
--
-- Stockés sur la config projet (par opérateur/pays ; Tonji = airtel/GA).
-- Appliqués à la cotisation : on refuse si montant_collecte + montant dépasserait
-- le plafond du type de cagnotte.
--
-- ⚠️ PROD (`tondo_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

ALTER TABLE public.tondo_project_config
  ADD COLUMN IF NOT EXISTS plafond_cagnotte_particulier integer NOT NULL DEFAULT 2500000;

ALTER TABLE public.tondo_project_config
  ADD COLUMN IF NOT EXISTS plafond_cagnotte_association integer NOT NULL DEFAULT 10000000;

-- ============================================================================
-- FIN 018_tondo_plafonds_cagnotte.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────
-- ▼ 019_tonji_frais_retrait.sql
-- ─────────────────────────────────────────────────────────

-- ============================================================================
-- 019_tondo_frais_retrait.sql
-- Frais de retrait CONFIGURABLES, en matrice (type de cotisation × type de user).
--
-- On avait retiré les frais de retrait (~3 %) du cotisant (018/amendement RÈGLE 3).
-- On les rend maintenant CONFIGURABLES par croisement :
--     type de cotisation : cagnotte | tontine
--     type de user       : particulier | association
-- → un TAUX (décimal, ex : 0.03 = 3 %) par cellule. Défaut 0 (= état actuel,
--   aucun frais de retrait facturé au cotisant).
--
-- Appliqué au calcul : montant_brut = ceil( net × (1 + frais_retrait) × (1 + commission) ).
-- Stocké en JSON sur la config projet (tondo_project_config).
--
-- ⚠️ PROD (`tondo_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

ALTER TABLE public.tondo_project_config
  ADD COLUMN IF NOT EXISTS frais_retrait json NOT NULL
  DEFAULT '{"cagnotte":{"particulier":0,"association":0},"tontine":{"particulier":0,"association":0}}'::json;

-- ============================================================================
-- FIN 019_tondo_frais_retrait.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────
-- ▼ 020_tonji_device_tokens.sql
-- ─────────────────────────────────────────────────────────

-- ============================================================================
-- 020_tondo_device_tokens.sql
-- Jetons push des appareils (FCM registration tokens).
--
-- Migration OneSignal → FCM/APNs en direct (gratuit à l'échelle, cf. plafond
-- MAU du plan gratuit OneSignal au 01/10/2026). OneSignal gérait pour nous le
-- mapping external_id ↔ device ; désormais c'est NOUS qui stockons, par user,
-- le(s) token(s) FCM de ses appareils. Le backend envoie ensuite chaque push
-- via l'API FCM HTTP v1 (un message par token).
--
-- Un même appareil (token) n'appartient qu'à UN user à la fois (le dernier
-- connecté) : à la connexion l'app (ré)enregistre son token → on réaffecte le
-- user_id ; à la déconnexion l'app supprime la ligne.
--
-- Style repris de 012_tondo_associations.sql (PK uuid gen_random_uuid,
-- project_id -> projects(id), trigger updated_at, RLS + policy projet, grants).
--
-- ⚠️ PROD (`tondo_`). En DEV (itgjlhaalodlgwsyrjnz), remplacer par `tondo_`.
--    IDEMPOTENT. À jouer dans le SQL Editor Supabase (la prod ne passe PAS par
--    `artisan migrate`).
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.tondo_device_tokens (
    id          uuid        DEFAULT gen_random_uuid() NOT NULL,
    project_id  uuid        NOT NULL,
    user_id     uuid        NOT NULL,          -- propriétaire actuel de l'appareil
    token       text        NOT NULL,          -- registration token FCM (Android + iOS via Firebase)
    plateforme  varchar(10) NOT NULL,          -- 'android' | 'ios'
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);

-- Clé primaire
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conname = 'tondo_device_tokens_pkey'
      AND conrelid = 'public.tondo_device_tokens'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_device_tokens
      ADD CONSTRAINT tondo_device_tokens_pkey PRIMARY KEY (id);
  END IF;
END $$;

-- Clés étrangères (projet + user)
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_device_tokens_project_fk'
      AND conrelid = 'public.tondo_device_tokens'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_device_tokens
      ADD CONSTRAINT tondo_device_tokens_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_device_tokens_user_fk'
      AND conrelid = 'public.tondo_device_tokens'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_device_tokens
      ADD CONSTRAINT tondo_device_tokens_user_fk
      FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;
  END IF;
END $$;

-- Contrainte de domaine sur la plateforme
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_device_tokens_plateforme_check'
      AND conrelid = 'public.tondo_device_tokens'::regclass
  ) THEN
    ALTER TABLE public.tondo_device_tokens
      ADD CONSTRAINT tondo_device_tokens_plateforme_check
      CHECK (plateforme IN ('android', 'ios'));
  END IF;
END $$;

-- Un token est unique par projet (le re-dépôt réaffecte simplement le user_id)
CREATE UNIQUE INDEX IF NOT EXISTS tondo_device_tokens_project_token_idx
  ON public.tondo_device_tokens (project_id, token);
-- Envoi ciblé : récupérer tous les tokens d'un user
CREATE INDEX IF NOT EXISTS tondo_device_tokens_user_idx
  ON public.tondo_device_tokens (project_id, user_id);

-- Maintien automatique de updated_at
DROP TRIGGER IF EXISTS trg_tondo_device_tokens_updated_at ON public.tondo_device_tokens;
CREATE TRIGGER trg_tondo_device_tokens_updated_at
  BEFORE UPDATE ON public.tondo_device_tokens
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- RLS + policy de cloisonnement projet (accès PostgREST ; Laravel = owner, bypass)
ALTER TABLE public.tondo_device_tokens ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tondo_device_tokens_all_same_project ON public.tondo_device_tokens;
CREATE POLICY tondo_device_tokens_all_same_project ON public.tondo_device_tokens
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

-- Droits
GRANT SELECT, INSERT, UPDATE, DELETE ON public.tondo_device_tokens TO authenticated;
GRANT ALL ON public.tondo_device_tokens TO service_role;

-- ============================================================================
-- FIN 020_tondo_device_tokens.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────
-- ▼ 021_tonji_plafond.sql
-- ─────────────────────────────────────────────────────────

-- ============================================================================
-- 021_tondo_plafond.sql
-- Plafond de collecte PERSONNALISÉ par compte + demandes de déblocage.
--
-- Contexte : jusqu'ici le plafond total d'une cagnotte est global par type de
-- compte (particulier ~2,5 M / association 10 M, config projet). On ajoute un
-- override PAR COMPTE :
--   - association → `tondo_organisations.plafond_fcfa` (déjà existant) ;
--   - particulier → nouvelle colonne `users.plafond_personnalise` (fixée par un
--     super_admin dans le dashboard).
--
-- Déblocage au-delà de 10 M pour une association (loi n°35/62 : autorisation du
-- Conseil des ministres) : l'asso dépose un JUSTIFICATIF depuis son profil, la
-- demande est examinée dans le dashboard, et l'admin fixe le plafond accordé.
--
-- Style repris de 012 / 020 (PK uuid gen_random_uuid, project_id -> projects,
-- trigger updated_at, RLS + policy projet, grants).
--
-- ⚠️ PROD (`tondo_`). En DEV (itgjlhaalodlgwsyrjnz), remplacer par `tondo_`.
--    IDEMPOTENT. À jouer dans le SQL Editor Supabase.
-- ============================================================================


-- ─────────────────────────────────────────────────────────────────────────
-- 1. Override de plafond pour un PARTICULIER (table users partagée)
--    NULL = pas d'override → on retombe sur le plafond global du type de compte.
-- ─────────────────────────────────────────────────────────────────────────
ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS plafond_personnalise bigint;


-- ─────────────────────────────────────────────────────────────────────────
-- 2. Demandes de déblocage de plafond (self-service, associations)
-- ─────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS public.tondo_plafond_demandes (
    id                   uuid        DEFAULT gen_random_uuid() NOT NULL,
    project_id           uuid        NOT NULL,
    user_id              uuid        NOT NULL,          -- demandeur (représentant asso)
    montant_demande      bigint,                        -- montant souhaité (indicatif)
    justificatif_chemin  text        NOT NULL,          -- chemin bucket Supabase
    justificatif_nom     text,                          -- nom d'origine du fichier
    justificatif_mime    text,                          -- type MIME
    justificatif_taille  bigint,                        -- taille en octets
    statut               varchar(20) NOT NULL DEFAULT 'en_attente',  -- en_attente|approuve|rejete
    motif                text,                          -- raison si rejetée
    plafond_accorde      bigint,                        -- plafond fixé par l'admin si approuvée
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now()
);

-- Clé primaire
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint
    WHERE conname = 'tondo_plafond_demandes_pkey'
      AND conrelid = 'public.tondo_plafond_demandes'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_plafond_demandes
      ADD CONSTRAINT tondo_plafond_demandes_pkey PRIMARY KEY (id);
  END IF;
END $$;

-- Clés étrangères (projet + user)
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_plafond_demandes_project_fk'
      AND conrelid = 'public.tondo_plafond_demandes'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_plafond_demandes
      ADD CONSTRAINT tondo_plafond_demandes_project_fk
      FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_plafond_demandes_user_fk'
      AND conrelid = 'public.tondo_plafond_demandes'::regclass
  ) THEN
    ALTER TABLE ONLY public.tondo_plafond_demandes
      ADD CONSTRAINT tondo_plafond_demandes_user_fk
      FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;
  END IF;
END $$;

-- Contrainte de domaine sur le statut
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'tondo_plafond_demandes_statut_check'
      AND conrelid = 'public.tondo_plafond_demandes'::regclass
  ) THEN
    ALTER TABLE public.tondo_plafond_demandes
      ADD CONSTRAINT tondo_plafond_demandes_statut_check
      CHECK (statut IN ('en_attente', 'approuve', 'rejete'));
  END IF;
END $$;

-- Une seule demande "vivante" par user : on remplace au re-dépôt (index sur user)
CREATE INDEX IF NOT EXISTS tondo_plafond_demandes_user_idx
  ON public.tondo_plafond_demandes (project_id, user_id);
-- File de modération par statut
CREATE INDEX IF NOT EXISTS tondo_plafond_demandes_statut_idx
  ON public.tondo_plafond_demandes (project_id, statut);

-- Maintien automatique de updated_at
DROP TRIGGER IF EXISTS trg_tondo_plafond_demandes_updated_at ON public.tondo_plafond_demandes;
CREATE TRIGGER trg_tondo_plafond_demandes_updated_at
  BEFORE UPDATE ON public.tondo_plafond_demandes
  FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- RLS + policy de cloisonnement projet
ALTER TABLE public.tondo_plafond_demandes ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tondo_plafond_demandes_all_same_project ON public.tondo_plafond_demandes;
CREATE POLICY tondo_plafond_demandes_all_same_project ON public.tondo_plafond_demandes
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

-- Droits
GRANT SELECT, INSERT, UPDATE, DELETE ON public.tondo_plafond_demandes TO authenticated;
GRANT ALL ON public.tondo_plafond_demandes TO service_role;

-- ============================================================================
-- FIN 021_tondo_plafond.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────
-- ▼ 022_tonji_commentaire_cotisation.sql
-- ─────────────────────────────────────────────────────────

-- ============================================================================
-- 022_tondo_commentaire_cotisation.sql
-- Commentaire libre et OPTIONNEL laissé par le cotisant au moment de payer.
--
-- Usage prévu : préciser une particularité du don, ou indiquer que l'on cotise
-- pour quelqu'un d'autre (« pour ma mère », « part de Jean », …).
--
-- Deux colonnes, pas une : sur Airtel le paiement est confirmé en ASYNCHRONE.
-- La ligne `tondo_paiements` n'est créée qu'à la confirmation, à partir de la
-- ligne `tondo_payin`. Le commentaire doit donc être porté par le payin dès
-- l'initiation pour survivre au polling ET à la réconciliation
-- (`tonji:reconcilier-payins`), qui recrée les paiements depuis les payin.
--
-- Champ purement descriptif : jamais utilisé dans un calcul, ni dans une règle
-- de gestion. Visible du gérant de la cagnotte et du cotisant, jamais publié
-- sur la page publique d'une cagnotte ouverte (surface d'abus).
--
-- ⚠️ PROD (`tondo_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

ALTER TABLE public.tondo_payin
  ADD COLUMN IF NOT EXISTS commentaire text;

ALTER TABLE public.tondo_paiements
  ADD COLUMN IF NOT EXISTS commentaire text;


-- ----------------------------------------------------------------------------
-- Vue unifiée du dashboard : expose `commentaire` sur les lignes payin.
--
-- Ajouté EN FIN de chaque SELECT : CREATE OR REPLACE VIEW n'autorise que
-- l'ajout de colonnes en queue, jamais l'insertion au milieu.
-- payout / payout_paynala sont des sorties système → commentaire NULL.
-- ----------------------------------------------------------------------------
CREATE OR REPLACE VIEW public.tondo_transactions_unified AS
 SELECT tondo_payin.id,
    'payin'::text AS type,
    tondo_payin.project_id,
    tondo_payin.cagnotte_id,
    tondo_payin.user_id,
    tondo_payin.trans_id,
    tondo_payin.operateur_id,
    tondo_payin.numero_tel,
    tondo_payin.montant,
    tondo_payin.statut,
    tondo_payin.request,
    tondo_payin.response,
    tondo_payin.date_creation,
    tondo_payin.created_at,
    tondo_payin.updated_at,
    tondo_payin.canal,
    tondo_payin.commentaire
   FROM public.tondo_payin
UNION ALL
 SELECT tondo_payout.id,
    'payout'::text AS type,
    tondo_payout.project_id,
    tondo_payout.cagnotte_id,
    tondo_payout.user_id,
    tondo_payout.trans_id,
    tondo_payout.operateur_id,
    tondo_payout.numero_tel,
    tondo_payout.montant,
    tondo_payout.statut,
    tondo_payout.request,
    tondo_payout.response,
    tondo_payout.date_creation,
    tondo_payout.created_at,
    tondo_payout.updated_at,
    NULL::varchar AS canal,
    NULL::text AS commentaire
   FROM public.tondo_payout
UNION ALL
 SELECT tondo_payout_paynala.id,
    'payout_paynala'::text AS type,
    tondo_payout_paynala.project_id,
    tondo_payout_paynala.cagnotte_id,
    NULL::uuid AS user_id,
    tondo_payout_paynala.trans_id,
    tondo_payout_paynala.operateur_id,
    NULL::text AS numero_tel,
    tondo_payout_paynala.montant,
    tondo_payout_paynala.statut,
    tondo_payout_paynala.request,
    tondo_payout_paynala.response,
    tondo_payout_paynala.date_creation,
    tondo_payout_paynala.created_at,
    tondo_payout_paynala.updated_at,
    NULL::varchar AS canal,
    NULL::text AS commentaire
   FROM public.tondo_payout_paynala;

GRANT SELECT ON public.tondo_transactions_unified TO authenticated;
GRANT SELECT ON public.tondo_transactions_unified TO service_role;

-- ============================================================================
-- FIN 022_tondo_commentaire_cotisation.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────
-- ▼ 023_cgu_acceptation.sql
-- ─────────────────────────────────────────────────────────

-- ============================================================================
-- 023_cgu_acceptation.sql
-- Mémorise quelle version des CGU un utilisateur a acceptée.
--
-- Les CGU sont générées à partir de la configuration opérateur
-- (GET /api/mobile/config/cgu) et portent une `version` : l'empreinte du texte
-- effectivement affiché. Changer un plafond, la matrice des frais de retrait ou
-- une formulation produit une nouvelle version ; un réglage qui n'apparaît pas
-- dans le texte n'en produit pas.
--
-- Comparer `cgu_version` à la version courante dit si l'utilisateur doit
-- réaccepter. NULL = n'a jamais accepté (comptes antérieurs à ce mécanisme).
--
-- ⚠️ La table `users` n'est PAS préfixée : ce fichier est identique en DEV et
-- en PROD, contrairement aux tables tondo_/tondo_. IDEMPOTENT.
-- ============================================================================

ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS cgu_version text;

ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS cgu_acceptee_at timestamptz;

COMMENT ON COLUMN public.users.cgu_version IS
  'Empreinte du texte des CGU accepté par l''utilisateur (GET /api/mobile/config/cgu).';

-- ============================================================================
-- FIN 023_cgu_acceptation.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────
-- ▼ 024_associations_sans_dossier.sql
-- ─────────────────────────────────────────────────────────

-- ============================================================================
-- 024_associations_sans_dossier.sql
-- Les associations ne fournissent plus de pièces justificatives.
--
-- Décision du 2026-09-09 : un compte associatif existe chez Airtel, qui a déjà
-- instruit l'identité au titre de son propre KYC. Paynala ne redemande donc
-- plus le récépissé, les statuts, le PV, la pièce d'identité ni l'autorisation
-- de collecte. Une association est acceptée d'emblée et accède directement à
-- l'accueil.
--
-- Le seul dossier encore étudié dans le dashboard est la demande de
-- dépassement du plafond de 10 M FCFA (`tondo_plafond_demandes`).
--
-- La table `tondo_organisation_documents` est VOLONTAIREMENT CONSERVÉE : elle
-- n'est plus ni écrite ni lue, mais resservira si des pièces sont exigées à
-- l'appui d'une demande de dépassement. Aucune donnée n'est supprimée.
--
-- ⚠️ PROD (`tondo_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

-- Nouveau défaut : approuvée dès la création.
ALTER TABLE public.tondo_organisations
  ALTER COLUMN statut SET DEFAULT 'approuve';

-- Les dossiers restés en attente n'ont plus de raison de l'être : personne ne
-- viendra les instruire, et leurs titulaires seraient bloqués hors de l'app.
-- Les statuts 'rejete' et 'suspendu' ne sont PAS touchés : ce sont des
-- décisions de modération, pas des dossiers en cours d'instruction.
UPDATE public.tondo_organisations
   SET statut = 'approuve', updated_at = now()
 WHERE statut = 'en_attente';

-- ============================================================================
-- FIN 024_associations_sans_dossier.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────
-- ▼ 025_backfill_type_compte.sql
-- ─────────────────────────────────────────────────────────

-- ============================================================================
-- 025_backfill_type_compte.sql
-- Renseigne `type_compte` pour les comptes antérieurs à la bascule associations.
--
-- Ces comptes sont à NULL et sont affichés « Non choisi » dans le dashboard.
-- Ils sont aiguillés vers l'écran de choix à chaque connexion.
--
-- ⚠️ ON NE LES MET PAS TOUS À 'particulier'. Un compte à NULL peut être une
-- association qui ne s'est jamais vue proposer le choix : le figer en
-- particulier contredirait son grade Airtel, et comme le gate ne se déclenche
-- que sur NULL, l'erreur ne serait jamais corrigée.
--
-- Seuls sont remplis ceux dont on est CERTAIN. `type_client` est stocké et
-- dérive du même grade que `type_compte` :
--   type_client = 'particulier'  ⟹  grade SUBS ou TEMP  ⟹  particulier, certain
--   type_client = 'entreprise'   ⟹  grade ≠ SUBS/TEMP   ⟹  peut être MERCHVIP
--                                                          ou HMERA, donc une
--                                                          association
--
-- Les autres restent à NULL et passeront par l'écran de choix, qui confronte
-- désormais le choix au KYC : ils seront correctement classés, ou bloqués si
-- leur profil n'est pas reconnu.
--
-- ⚠️ La table `users` n'est PAS préfixée : identique en DEV et en PROD.
-- IDEMPOTENT.
-- ============================================================================

UPDATE public.users
   SET type_compte = 'particulier',
       updated_at  = now()
 WHERE type_compte IS NULL
   AND type_client = 'particulier';

-- Contrôle : ce qui reste à NULL après coup, par type_client.
-- Attendu : uniquement des 'entreprise' / 'marchand' / NULL.
--   SELECT type_client, count(*) FROM public.users
--    WHERE type_compte IS NULL GROUP BY type_client;

-- ============================================================================
-- FIN 025_backfill_type_compte.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────
-- ▼ 026_tonji_evenements.sql
-- ─────────────────────────────────────────────────────────

-- ============================================================================
-- 026_tondo_evenements.sql
-- Télémétrie produit : ce que les utilisateurs font dans l'app.
--
-- Distincte de `tondo_logs`, qui journalise les actions ADMIN (acteur_admin_id,
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
-- ⚠️ PROD (`tondo_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.tondo_evenements (
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
CREATE INDEX IF NOT EXISTS tondo_evenements_nom_date_idx
  ON public.tondo_evenements (project_id, nom, occurred_at DESC);

-- Reconstitution d'un parcours complet.
CREATE INDEX IF NOT EXISTS tondo_evenements_session_idx
  ON public.tondo_evenements (session_id, occurred_at);

-- Purge des données brutes au-delà de la rétention (90 jours).
CREATE INDEX IF NOT EXISTS tondo_evenements_created_idx
  ON public.tondo_evenements (created_at);

ALTER TABLE public.tondo_evenements ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "tondo_evenements_same_project" ON public.tondo_evenements;
CREATE POLICY "tondo_evenements_same_project" ON public.tondo_evenements
  FOR ALL
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

GRANT SELECT, INSERT ON public.tondo_evenements TO authenticated;
GRANT ALL    ON public.tondo_evenements TO service_role;

COMMENT ON TABLE public.tondo_evenements IS
  'Télémétrie produit (comportement utilisateur). Aucun contenu saisi : ni numéro, ni montant exact, ni commentaire.';

-- ============================================================================
-- FIN 026_tondo_evenements.sql
-- ============================================================================


-- ─────────────────────────────────────────────────────────
-- ▼ 027_tonji_evenements_jour.sql
-- ─────────────────────────────────────────────────────────

-- ============================================================================
-- 027_tondo_evenements_jour.sql
-- Agrégats quotidiens de la télémétrie produit.
--
-- Le dashboard lit CETTE table, jamais `tondo_evenements`. À la cible de
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
-- ⚠️ PROD (`tondo_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.tondo_evenements_jour (
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
CREATE UNIQUE INDEX IF NOT EXISTS tondo_evenements_jour_cle_idx
  ON public.tondo_evenements_jour (project_id, jour, nom, canal, plateforme);

-- Lecture du dashboard : une série sur une période.
CREATE INDEX IF NOT EXISTS tondo_evenements_jour_serie_idx
  ON public.tondo_evenements_jour (project_id, nom, jour DESC);

ALTER TABLE public.tondo_evenements_jour ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "tondo_evenements_jour_same_project" ON public.tondo_evenements_jour;
CREATE POLICY "tondo_evenements_jour_same_project" ON public.tondo_evenements_jour
  FOR ALL
  USING (project_id = public.current_project_id())
  WITH CHECK (project_id = public.current_project_id());

GRANT SELECT ON public.tondo_evenements_jour TO authenticated;
GRANT ALL    ON public.tondo_evenements_jour TO service_role;

COMMENT ON TABLE public.tondo_evenements_jour IS
  'Agrégats quotidiens de télémétrie. Lus par le dashboard ; les lignes brutes sont purgées à 90 jours.';

-- ============================================================================
-- FIN 027_tondo_evenements_jour.sql
-- ============================================================================
