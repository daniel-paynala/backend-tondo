-- ================================================================
-- Bootstrap prod Tondo
-- Structure complète du schéma public (tables VIDES) + données de
-- référence (migrations, projects, tonji_project_config, tonji_admins).
-- Généré depuis la base source. Aucune donnée de test copiée.
-- ================================================================

--
-- PostgreSQL database dump
--


-- Dumped from database version 17.6
-- Dumped by pg_dump version 18.4

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: public; Type: SCHEMA; Schema: -; Owner: -
--

-- CREATE SCHEMA public;  (déjà présent sur Supabase)


--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: -
--

-- COMMENT ON SCHEMA public IS 'standard public schema';


--
-- Name: sexe; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.sexe AS ENUM (
    'homme',
    'femme'
);


--
-- Name: tonji_jour_semaine; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.tonji_jour_semaine AS ENUM (
    'lundi',
    'mardi',
    'mercredi',
    'jeudi',
    'vendredi',
    'samedi',
    'dimanche'
);


--
-- Name: tonji_motif_signalement; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.tonji_motif_signalement AS ENUM (
    'fraude_suspectee',
    'contenu_inapproprie',
    'doublon',
    'autre'
);


--
-- Name: tonji_niveau_log; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.tonji_niveau_log AS ENUM (
    'info',
    'warning',
    'error'
);


--
-- Name: tonji_periodicite; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.tonji_periodicite AS ENUM (
    'hebdomadaire',
    'mensuelle'
);


--
-- Name: tonji_role_admin; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.tonji_role_admin AS ENUM (
    'super_admin',
    'admin',
    'operateur',
    'lecteur'
);


--
-- Name: tonji_role_utilisateur; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.tonji_role_utilisateur AS ENUM (
    'gerant',
    'cotiseur'
);


--
-- Name: tonji_statut_cagnotte; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.tonji_statut_cagnotte AS ENUM (
    'active',
    'cloturee',
    'en_cours'
);


--
-- Name: tonji_statut_paiement; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.tonji_statut_paiement AS ENUM (
    'paye',
    'en_attente',
    'en_retard'
);


--
-- Name: tonji_statut_signalement; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.tonji_statut_signalement AS ENUM (
    'nouveau',
    'en_traitement',
    'resolu',
    'rejete'
);


--
-- Name: tonji_statut_transaction; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.tonji_statut_transaction AS ENUM (
    'initie',
    'en_cours',
    'succes',
    'echec',
    'annule'
);


--
-- Name: tonji_type_cagnotte; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.tonji_type_cagnotte AS ENUM (
    'tontine_periodique',
    'cagnotte_ouverte'
);


--
-- Name: type_client; Type: TYPE; Schema: public; Owner: -
--

CREATE TYPE public.type_client AS ENUM (
    'particulier',
    'entreprise',
    'marchand'
);


--
-- Name: current_project_id(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.current_project_id() RETURNS uuid
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
  select project_id
  from public.users
  where id = auth.uid()
$$;


--
-- Name: FUNCTION current_project_id(); Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON FUNCTION public.current_project_id() IS 'Retourne le project_id du user actuellement authentifié (via auth.uid()).';


--
-- Name: handle_new_auth_user(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.handle_new_auth_user() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'public'
    AS $$
declare
  v_project_id uuid;
  v_slug       text;
begin
  v_slug := coalesce(new.raw_user_meta_data->>'project_slug', 'tondo');

  select id into v_project_id from public.projects where slug = v_slug;
  if v_project_id is null then
    raise exception 'Project slug not found in registry: %', v_slug;
  end if;

  insert into public.users (
    id,
    project_id,
    nom,
    prenom,
    date_naissance,
    numero,
    type_client
  ) values (
    new.id,
    v_project_id,
    coalesce(new.raw_user_meta_data->>'nom', ''),
    coalesce(new.raw_user_meta_data->>'prenom', ''),
    coalesce((new.raw_user_meta_data->>'date_naissance')::date, current_date),
    coalesce(new.phone, ''),
    coalesce(
      (new.raw_user_meta_data->>'type_client')::public.type_client,
      'particulier'
    )
  );

  return new;
end;
$$;


--
-- Name: rls_auto_enable(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.rls_auto_enable() RETURNS event_trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
  cmd record;
BEGIN
  FOR cmd IN
    SELECT *
    FROM pg_event_trigger_ddl_commands()
    WHERE command_tag IN ('CREATE TABLE', 'CREATE TABLE AS', 'SELECT INTO')
      AND object_type IN ('table','partitioned table')
  LOOP
     IF cmd.schema_name IS NOT NULL AND cmd.schema_name IN ('public') AND cmd.schema_name NOT IN ('pg_catalog','information_schema') AND cmd.schema_name NOT LIKE 'pg_toast%' AND cmd.schema_name NOT LIKE 'pg_temp%' THEN
      BEGIN
        EXECUTE format('alter table if exists %s enable row level security', cmd.object_identity);
        RAISE LOG 'rls_auto_enable: enabled RLS on %', cmd.object_identity;
      EXCEPTION
        WHEN OTHERS THEN
          RAISE LOG 'rls_auto_enable: failed to enable RLS on %', cmd.object_identity;
      END;
     ELSE
        RAISE LOG 'rls_auto_enable: skip % (either system schema or not in enforced list: %.)', cmd.object_identity, cmd.schema_name;
     END IF;
  END LOOP;
END;
$$;


--
-- Name: set_updated_at(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.set_updated_at() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
begin
  new.updated_at = now();
  return new;
end;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: personal_access_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.personal_access_tokens (
    id bigint NOT NULL,
    tokenable_type character varying(255) NOT NULL,
    tokenable_id uuid NOT NULL,
    name text NOT NULL,
    token character varying(64) NOT NULL,
    abilities text,
    last_used_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.personal_access_tokens_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: personal_access_tokens_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.personal_access_tokens_id_seq OWNED BY public.personal_access_tokens.id;


--
-- Name: projects; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.projects (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    slug text NOT NULL,
    nom text NOT NULL,
    prefixe text NOT NULL,
    created_at timestamp with time zone DEFAULT now()
);


--
-- Name: TABLE projects; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.projects IS 'Registry des produits hébergés sur cette base. Chaque projet a un slug et un préfixe qui scopent ses tables métier.';


--
-- Name: tonji_admins; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tonji_admins (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    project_id uuid NOT NULL,
    email text NOT NULL,
    password_hash text NOT NULL,
    nom text NOT NULL,
    prenom text NOT NULL,
    role public.tonji_role_admin DEFAULT 'admin'::public.tonji_role_admin NOT NULL,
    actif boolean DEFAULT true NOT NULL,
    derniere_connexion timestamp with time zone,
    remember_token text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: TABLE tonji_admins; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.tonji_admins IS 'Administrateurs du dashboard Tondo. Authentification email + bcrypt via Laravel Sanctum. Distinct de public.users (qui auth via phone OTP Supabase).';


--
-- Name: COLUMN tonji_admins.password_hash; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_admins.password_hash IS 'Hash bcrypt généré par Laravel Hash::make() (cost 12). Format $2y$...';


--
-- Name: tonji_cagnottes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tonji_cagnottes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    reference text NOT NULL,
    project_id uuid NOT NULL,
    user_id uuid NOT NULL,
    titre text NOT NULL,
    type public.tonji_type_cagnotte NOT NULL,
    statut public.tonji_statut_cagnotte DEFAULT 'active'::public.tonji_statut_cagnotte NOT NULL,
    montant_collecte bigint DEFAULT 0 NOT NULL,
    montant_beneficiaire bigint,
    montant_avec_frais bigint,
    nombre_participants integer DEFAULT 0 NOT NULL,
    nombre_splits integer,
    nombre_envois integer,
    numero_retrait_masque text,
    montant_cible bigint,
    date_fin timestamp with time zone,
    montant_par_cycle bigint,
    periodicite public.tonji_periodicite,
    intervalle integer DEFAULT 1 NOT NULL,
    jour_semaine public.tonji_jour_semaine,
    jour_mois integer,
    date_creation timestamp with time zone DEFAULT now() NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    total_a_envoyer bigint,
    nombre_inscrits bigint DEFAULT '0'::bigint NOT NULL,
    date_demarrage timestamp with time zone,
    penalite_active boolean DEFAULT true NOT NULL,
    penalite_montant bigint,
    penalite_frequence text,
    reversement_auto boolean DEFAULT false NOT NULL,
    reversement_auto_frequence_mois smallint,
    numero_retrait character varying(20),
    visibilite character varying(16) DEFAULT 'prive'::character varying NOT NULL,
    statut_validation character varying(24) DEFAULT 'non_requis'::character varying NOT NULL,
    description text,
    motif_rejet text,
    validee_at timestamp(0) without time zone,
    validee_par character varying(255),
    CONSTRAINT tonji_cagnottes_jour_mois_check CHECK (((jour_mois >= 1) AND (jour_mois <= 28))),
    CONSTRAINT tonji_cagnottes_penalite_frequence_check CHECK ((penalite_frequence = ANY (ARRAY['heure'::text, 'jour'::text])))
);


--
-- Name: TABLE tonji_cagnottes; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.tonji_cagnottes IS 'Cagnotte Tondo : tontine périodique OU cotisation ouverte. user_id = gérant créateur.';


--
-- Name: COLUMN tonji_cagnottes.id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_cagnottes.id IS 'UUID interne. Jamais exposé à l''utilisateur. Utilisé pour les FK et les routes API.';


--
-- Name: COLUMN tonji_cagnottes.reference; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_cagnottes.reference IS 'Identifiant public 4-5 chiffres. C''est CE QUE L''UTILISATEUR VOIT, SAISIT EN USSD ET PARTAGE.';


--
-- Name: COLUMN tonji_cagnottes.montant_beneficiaire; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_cagnottes.montant_beneficiaire IS 'Montant net que le bénéficiaire recevra. Pour tontine : montant_par_cycle × nombre_participants. Pour cotisation : montant_cible.';


--
-- Name: COLUMN tonji_cagnottes.montant_avec_frais; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_cagnottes.montant_avec_frais IS 'Montant brut payé par le cotisant = montant_beneficiaire + frais Paynala (2 %) + frais opérateur.';


--
-- Name: COLUMN tonji_cagnottes.nombre_splits; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_cagnottes.nombre_splits IS 'Découpage technique du montant_beneficiaire dicté par le plafond Mobile Money (500 000 FCFA par transaction, RÈGLE 4-bis). Si montant_beneficiaire <= 500k, splits = 1. Sinon splits = ceil(montant_beneficiaire / 500k). Ex : 600k → 2 splits, 3M → 6 splits.';


--
-- Name: COLUMN tonji_cagnottes.nombre_envois; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_cagnottes.nombre_envois IS 'Nombre d''envois Mobile Money réels effectués au bénéficiaire. Égal à nombre_splits dans le cas idéal, MAIS peut être supérieur si les frais opérateur (3 % retrait) prélevés sur chaque envoi nécessitent un envoi supplémentaire de régularisation pour que le bénéficiaire reçoive exactement le montant_beneficiaire annoncé. Ex : 3M → 6 splits + 1 envoi correction = 7 envois.';


--
-- Name: COLUMN tonji_cagnottes.date_demarrage; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_cagnottes.date_demarrage IS 'Quand la tontine a officiellement démarré (statut → en_cours).';


--
-- Name: COLUMN tonji_cagnottes.penalite_active; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_cagnottes.penalite_active IS 'Vrai si une pénalité de retard est appliquée. Actif par défaut. Le gérant désactive en cochant "Pas de pénalité" à la création.';


--
-- Name: COLUMN tonji_cagnottes.penalite_montant; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_cagnottes.penalite_montant IS 'Montant de la pénalité par période (FCFA). NULL si penalite_active = false.';


--
-- Name: COLUMN tonji_cagnottes.penalite_frequence; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_cagnottes.penalite_frequence IS 'Période de la pénalité : "heure" ou "jour". NULL si penalite_active = false.';


--
-- Name: tonji_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tonji_logs (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    project_id uuid NOT NULL,
    acteur_admin_id uuid,
    acteur_user_id uuid,
    acteur_libelle text NOT NULL,
    acteur_role text NOT NULL,
    action text NOT NULL,
    cible text,
    niveau public.tonji_niveau_log DEFAULT 'info'::public.tonji_niveau_log NOT NULL,
    metadonnees jsonb,
    date timestamp with time zone DEFAULT now() NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: tonji_paiements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tonji_paiements (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    project_id uuid NOT NULL,
    cagnotte_id uuid NOT NULL,
    participant_id uuid NOT NULL,
    user_id uuid,
    montant bigint NOT NULL,
    date timestamp with time zone DEFAULT now() NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT tonji_paiements_montant_check CHECK ((montant > 0))
);


--
-- Name: tonji_paiements_en_attente; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tonji_paiements_en_attente (
    trans_id character varying(255) NOT NULL,
    numero_wa character varying(30) NOT NULL,
    project_id character varying(255) NOT NULL,
    cagnotte_ref character varying(10) NOT NULL,
    montant integer NOT NULL,
    prenom character varying(100) NOT NULL,
    user_id character varying(255) NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: tonji_participants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tonji_participants (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    project_id uuid NOT NULL,
    cagnotte_id uuid NOT NULL,
    user_id uuid,
    nom text NOT NULL,
    prenom text NOT NULL,
    numero_masque text NOT NULL,
    statut_paiement public.tonji_statut_paiement DEFAULT 'en_attente'::public.tonji_statut_paiement NOT NULL,
    montant_paye bigint DEFAULT 0 NOT NULL,
    date_dernier_paiement timestamp with time zone,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    ordre_passage smallint DEFAULT 0 NOT NULL,
    numero_retrait_masque text,
    est_compte_light boolean DEFAULT false NOT NULL,
    updated_at timestamp with time zone
);


--
-- Name: COLUMN tonji_participants.numero_retrait_masque; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_participants.numero_retrait_masque IS 'Numéro Mobile Money sur lequel le participant recevra les fonds. NULL = même que numero_masque.';


--
-- Name: COLUMN tonji_participants.est_compte_light; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_participants.est_compte_light IS 'Vrai si le participant est un invité sans compte Tondo (user_id null, ajouté manuellement par le gérant).';


--
-- Name: tonji_payin; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tonji_payin (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    project_id uuid NOT NULL,
    cagnotte_id uuid NOT NULL,
    user_id uuid,
    trans_id text NOT NULL,
    operateur_id text,
    numero_tel text NOT NULL,
    montant bigint NOT NULL,
    statut public.tonji_statut_transaction DEFAULT 'initie'::public.tonji_statut_transaction NOT NULL,
    request jsonb DEFAULT '{}'::jsonb NOT NULL,
    response jsonb,
    date_creation timestamp with time zone DEFAULT now() NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    montant_penalite bigint DEFAULT 0 NOT NULL,
    CONSTRAINT tonji_payin_montant_check CHECK ((montant > 0))
);


--
-- Name: TABLE tonji_payin; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.tonji_payin IS 'Cotisation entrante. Trace chaque appel API à l''opérateur Mobile Money pour débiter le cotisant et créditer la cagnotte.';


--
-- Name: COLUMN tonji_payin.user_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_payin.user_id IS 'Compte Tondo du cotisant si existant. Nullable car un cotiseur peut payer sans avoir de compte.';


--
-- Name: COLUMN tonji_payin.trans_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_payin.trans_id IS 'Identifiant Tondo human-readable de la transaction (ex : TONDO-PAYIN-00012345).';


--
-- Name: COLUMN tonji_payin.operateur_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_payin.operateur_id IS 'Identifiant unique de la transaction côté opérateur (Airtel/Moov/agrégateur). Sert à la réconciliation.';


--
-- Name: COLUMN tonji_payin.montant_penalite; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_payin.montant_penalite IS 'Part de la pénalité de retard dans ce paiement (FCFA). 0 si le paiement était à temps. Pas soumise aux frais Paynala/Airtel.';


--
-- Name: tonji_payout; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tonji_payout (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    project_id uuid NOT NULL,
    cagnotte_id uuid NOT NULL,
    user_id uuid,
    trans_id text NOT NULL,
    operateur_id text,
    numero_tel text NOT NULL,
    montant bigint NOT NULL,
    statut public.tonji_statut_transaction DEFAULT 'initie'::public.tonji_statut_transaction NOT NULL,
    request jsonb DEFAULT '{}'::jsonb NOT NULL,
    response jsonb,
    date_creation timestamp with time zone DEFAULT now() NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT tonji_payout_montant_check CHECK ((montant > 0))
);


--
-- Name: TABLE tonji_payout; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.tonji_payout IS 'Envoi de fonds vers le bénéficiaire de la cagnotte (utilisateur final qui touche). Une cagnotte peut générer plusieurs payouts si montant_beneficiaire > 500k FCFA (cf. nombre_splits / nombre_envois sur tonji_cagnottes).';


--
-- Name: COLUMN tonji_payout.user_id; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON COLUMN public.tonji_payout.user_id IS 'Compte Tondo du bénéficiaire si existant. Nullable car le numéro de retrait peut désigner quelqu''un sans compte Tondo.';


--
-- Name: tonji_payout_paynala; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tonji_payout_paynala (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    project_id uuid NOT NULL,
    cagnotte_id uuid NOT NULL,
    trans_id text NOT NULL,
    operateur_id text,
    montant bigint NOT NULL,
    statut public.tonji_statut_transaction DEFAULT 'initie'::public.tonji_statut_transaction NOT NULL,
    request jsonb DEFAULT '{}'::jsonb NOT NULL,
    response jsonb,
    date_creation timestamp with time zone DEFAULT now() NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT tonji_payout_paynala_montant_check CHECK ((montant > 0))
);


--
-- Name: TABLE tonji_payout_paynala; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.tonji_payout_paynala IS 'Encaissement par Paynala de la commission 2 % sur chaque cotisation. Le destinataire est l''entité Paynala (configurée chez l''opérateur), pas un user final.';


--
-- Name: tonji_project_config; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tonji_project_config (
    id uuid NOT NULL,
    project_id uuid NOT NULL,
    commission_paynala numeric(6,4) DEFAULT 0.02 NOT NULL,
    plafond_par_envoi integer DEFAULT 500000 NOT NULL,
    plafond_journalier integer DEFAULT 2500000 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    operateur character varying(50) DEFAULT 'airtel'::character varying NOT NULL,
    pays character varying(5) DEFAULT 'GA'::character varying NOT NULL,
    tranches json DEFAULT '[]'::json NOT NULL,
    indicatif character varying(10),
    prefixes json,
    actif boolean DEFAULT true NOT NULL,
    logo text
);


--
-- Name: tonji_retry; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tonji_retry (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    project_id uuid NOT NULL,
    payin_id uuid,
    payout_id uuid,
    payout_paynala_id uuid,
    tentative integer NOT NULL,
    request jsonb,
    response jsonb,
    statut public.tonji_statut_transaction NOT NULL,
    erreur_message text,
    date_creation timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT tonji_retry_exactly_one_fk CHECK ((((
CASE
    WHEN (payin_id IS NULL) THEN 0
    ELSE 1
END +
CASE
    WHEN (payout_id IS NULL) THEN 0
    ELSE 1
END) +
CASE
    WHEN (payout_paynala_id IS NULL) THEN 0
    ELSE 1
END) = 1)),
    CONSTRAINT tonji_retry_tentative_check CHECK ((tentative >= 1))
);


--
-- Name: TABLE tonji_retry; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.tonji_retry IS 'Historique des tentatives sur les transactions payin / payout / payout_paynala. Audit-only : on n''édite jamais une row, on en INSERT une nouvelle à chaque tentative.';


--
-- Name: tonji_signalements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tonji_signalements (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    project_id uuid NOT NULL,
    cagnotte_id uuid NOT NULL,
    signale_par_user_id uuid,
    signale_par_libelle text NOT NULL,
    motif public.tonji_motif_signalement NOT NULL,
    description text NOT NULL,
    statut public.tonji_statut_signalement DEFAULT 'nouveau'::public.tonji_statut_signalement NOT NULL,
    resolu_par_admin_id uuid,
    resolu_le timestamp with time zone,
    resolu_commentaire text,
    date_creation timestamp with time zone DEFAULT now() NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: tonji_transactions_unified; Type: VIEW; Schema: public; Owner: -
--

CREATE VIEW public.tonji_transactions_unified AS
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
    tonji_payin.updated_at
   FROM public.tonji_payin
UNION ALL
 SELECT tonji_payout.id,
    'payout'::text AS type,
    tonji_payout.project_id,
    tonji_payout.cagnotte_id,
    tonji_payout.user_id,
    tonji_payout.trans_id,
    tonji_payout.operateur_id,
    tonji_payout.numero_tel,
    tonji_payout.montant,
    tonji_payout.statut,
    tonji_payout.request,
    tonji_payout.response,
    tonji_payout.date_creation,
    tonji_payout.created_at,
    tonji_payout.updated_at
   FROM public.tonji_payout
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
    tonji_payout_paynala.updated_at
   FROM public.tonji_payout_paynala;


--
-- Name: VIEW tonji_transactions_unified; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON VIEW public.tonji_transactions_unified IS 'Union des 3 tables transactionnelles. Lecture-seule. Filtrer par project_id et type côté requête.';


--
-- Name: tonji_whatsapp_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.tonji_whatsapp_logs (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    message_sid text NOT NULL,
    statut character varying(30),
    numero_dest character varying(30),
    numero_src character varying(30),
    error_code character varying(10),
    error_message text,
    created_at timestamp with time zone DEFAULT now(),
    updated_at timestamp with time zone DEFAULT now()
);


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id uuid NOT NULL,
    project_id uuid NOT NULL,
    nom text NOT NULL,
    prenom text NOT NULL,
    date_naissance date,
    numero text NOT NULL,
    type_client public.type_client DEFAULT 'particulier'::public.type_client NOT NULL,
    kyc_valide boolean DEFAULT false NOT NULL,
    sexe public.sexe,
    adresse text,
    email text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    operateur character varying(50),
    pays character(2),
    indicatif character varying(10),
    compte_type character varying(10) DEFAULT 'full'::character varying NOT NULL,
    certifie_majeur boolean DEFAULT false NOT NULL
);


--
-- Name: TABLE users; Type: COMMENT; Schema: public; Owner: -
--

COMMENT ON TABLE public.users IS 'Profil utilisateur étendu. id = auth.users.id (FK). project_id scope l''appartenance.';


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: personal_access_tokens id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens ALTER COLUMN id SET DEFAULT nextval('public.personal_access_tokens_id_seq'::regclass);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_pkey PRIMARY KEY (id);


--
-- Name: personal_access_tokens personal_access_tokens_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.personal_access_tokens
    ADD CONSTRAINT personal_access_tokens_token_unique UNIQUE (token);


--
-- Name: projects projects_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_pkey PRIMARY KEY (id);


--
-- Name: projects projects_prefixe_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_prefixe_key UNIQUE (prefixe);


--
-- Name: projects projects_slug_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.projects
    ADD CONSTRAINT projects_slug_key UNIQUE (slug);


--
-- Name: tonji_admins tonji_admins_email_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_admins
    ADD CONSTRAINT tonji_admins_email_key UNIQUE (email);


--
-- Name: tonji_admins tonji_admins_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_admins
    ADD CONSTRAINT tonji_admins_pkey PRIMARY KEY (id);


--
-- Name: tonji_cagnottes tonji_cagnottes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_cagnottes
    ADD CONSTRAINT tonji_cagnottes_pkey PRIMARY KEY (id);


--
-- Name: tonji_cagnottes tonji_cagnottes_reference_check; Type: CHECK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE public.tonji_cagnottes
    ADD CONSTRAINT tonji_cagnottes_reference_check CHECK ((reference ~ '^\d{6}$'::text)) NOT VALID;


--
-- Name: tonji_cagnottes tonji_cagnottes_reference_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_cagnottes
    ADD CONSTRAINT tonji_cagnottes_reference_key UNIQUE (reference);


--
-- Name: tonji_logs tonji_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_logs
    ADD CONSTRAINT tonji_logs_pkey PRIMARY KEY (id);


--
-- Name: tonji_paiements_en_attente tonji_paiements_en_attente_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_paiements_en_attente
    ADD CONSTRAINT tonji_paiements_en_attente_pkey PRIMARY KEY (trans_id);


--
-- Name: tonji_paiements tonji_paiements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_paiements
    ADD CONSTRAINT tonji_paiements_pkey PRIMARY KEY (id);


--
-- Name: tonji_participants tonji_participants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_participants
    ADD CONSTRAINT tonji_participants_pkey PRIMARY KEY (id);


--
-- Name: tonji_payin tonji_payin_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_payin
    ADD CONSTRAINT tonji_payin_pkey PRIMARY KEY (id);


--
-- Name: tonji_payin tonji_payin_trans_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_payin
    ADD CONSTRAINT tonji_payin_trans_id_key UNIQUE (trans_id);


--
-- Name: tonji_payout_paynala tonji_payout_paynala_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_payout_paynala
    ADD CONSTRAINT tonji_payout_paynala_pkey PRIMARY KEY (id);


--
-- Name: tonji_payout_paynala tonji_payout_paynala_trans_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_payout_paynala
    ADD CONSTRAINT tonji_payout_paynala_trans_id_key UNIQUE (trans_id);


--
-- Name: tonji_payout tonji_payout_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_payout
    ADD CONSTRAINT tonji_payout_pkey PRIMARY KEY (id);


--
-- Name: tonji_payout tonji_payout_trans_id_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_payout
    ADD CONSTRAINT tonji_payout_trans_id_key UNIQUE (trans_id);


--
-- Name: tonji_project_config tonji_project_config_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_project_config
    ADD CONSTRAINT tonji_project_config_pkey PRIMARY KEY (id);


--
-- Name: tonji_project_config tonji_project_config_project_id_operateur_pays_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_project_config
    ADD CONSTRAINT tonji_project_config_project_id_operateur_pays_unique UNIQUE (project_id, operateur, pays);


--
-- Name: tonji_retry tonji_retry_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_retry
    ADD CONSTRAINT tonji_retry_pkey PRIMARY KEY (id);


--
-- Name: tonji_signalements tonji_signalements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_signalements
    ADD CONSTRAINT tonji_signalements_pkey PRIMARY KEY (id);


--
-- Name: tonji_whatsapp_logs tonji_whatsapp_logs_message_sid_key; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_whatsapp_logs
    ADD CONSTRAINT tonji_whatsapp_logs_message_sid_key UNIQUE (message_sid);


--
-- Name: tonji_whatsapp_logs tonji_whatsapp_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_whatsapp_logs
    ADD CONSTRAINT tonji_whatsapp_logs_pkey PRIMARY KEY (id);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: personal_access_tokens_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_expires_at_index ON public.personal_access_tokens USING btree (expires_at);


--
-- Name: personal_access_tokens_tokenable_type_tokenable_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index ON public.personal_access_tokens USING btree (tokenable_type, tokenable_id);


--
-- Name: tonji_admins_email_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_admins_email_idx ON public.tonji_admins USING btree (email);


--
-- Name: tonji_admins_project_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_admins_project_id_idx ON public.tonji_admins USING btree (project_id);


--
-- Name: tonji_cagnottes_project_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_cagnottes_project_id_idx ON public.tonji_cagnottes USING btree (project_id);


--
-- Name: tonji_cagnottes_reference_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_cagnottes_reference_idx ON public.tonji_cagnottes USING btree (reference);


--
-- Name: tonji_cagnottes_statut_type_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_cagnottes_statut_type_idx ON public.tonji_cagnottes USING btree (statut, type);


--
-- Name: tonji_cagnottes_user_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_cagnottes_user_id_idx ON public.tonji_cagnottes USING btree (user_id);


--
-- Name: tonji_logs_acteur_role_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_logs_acteur_role_idx ON public.tonji_logs USING btree (acteur_role);


--
-- Name: tonji_logs_date_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_logs_date_idx ON public.tonji_logs USING btree (date DESC);


--
-- Name: tonji_logs_niveau_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_logs_niveau_idx ON public.tonji_logs USING btree (niveau);


--
-- Name: tonji_logs_project_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_logs_project_id_idx ON public.tonji_logs USING btree (project_id);


--
-- Name: tonji_paiements_cagnotte_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_paiements_cagnotte_id_idx ON public.tonji_paiements USING btree (cagnotte_id);


--
-- Name: tonji_paiements_date_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_paiements_date_idx ON public.tonji_paiements USING btree (date DESC);


--
-- Name: tonji_paiements_participant_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_paiements_participant_id_idx ON public.tonji_paiements USING btree (participant_id);


--
-- Name: tonji_paiements_user_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_paiements_user_id_idx ON public.tonji_paiements USING btree (user_id);


--
-- Name: tonji_participants_cagnotte_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_participants_cagnotte_id_idx ON public.tonji_participants USING btree (cagnotte_id);


--
-- Name: tonji_participants_cagnotte_user_uniq; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX tonji_participants_cagnotte_user_uniq ON public.tonji_participants USING btree (cagnotte_id, user_id) WHERE (user_id IS NOT NULL);


--
-- Name: tonji_participants_project_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_participants_project_id_idx ON public.tonji_participants USING btree (project_id);


--
-- Name: tonji_participants_user_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_participants_user_id_idx ON public.tonji_participants USING btree (user_id);


--
-- Name: tonji_payin_cagnotte_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_payin_cagnotte_id_idx ON public.tonji_payin USING btree (cagnotte_id);


--
-- Name: tonji_payin_date_creation_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_payin_date_creation_idx ON public.tonji_payin USING btree (date_creation DESC);


--
-- Name: tonji_payin_operateur_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_payin_operateur_id_idx ON public.tonji_payin USING btree (operateur_id);


--
-- Name: tonji_payin_statut_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_payin_statut_idx ON public.tonji_payin USING btree (statut);


--
-- Name: tonji_payin_user_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_payin_user_id_idx ON public.tonji_payin USING btree (user_id);


--
-- Name: tonji_payout_cagnotte_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_payout_cagnotte_id_idx ON public.tonji_payout USING btree (cagnotte_id);


--
-- Name: tonji_payout_date_creation_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_payout_date_creation_idx ON public.tonji_payout USING btree (date_creation DESC);


--
-- Name: tonji_payout_operateur_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_payout_operateur_id_idx ON public.tonji_payout USING btree (operateur_id);


--
-- Name: tonji_payout_paynala_cagnotte_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_payout_paynala_cagnotte_id_idx ON public.tonji_payout_paynala USING btree (cagnotte_id);


--
-- Name: tonji_payout_paynala_date_creation_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_payout_paynala_date_creation_idx ON public.tonji_payout_paynala USING btree (date_creation DESC);


--
-- Name: tonji_payout_paynala_operateur_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_payout_paynala_operateur_id_idx ON public.tonji_payout_paynala USING btree (operateur_id);


--
-- Name: tonji_payout_paynala_statut_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_payout_paynala_statut_idx ON public.tonji_payout_paynala USING btree (statut);


--
-- Name: tonji_payout_statut_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_payout_statut_idx ON public.tonji_payout USING btree (statut);


--
-- Name: tonji_payout_user_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_payout_user_id_idx ON public.tonji_payout USING btree (user_id);


--
-- Name: tonji_retry_date_creation_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_retry_date_creation_idx ON public.tonji_retry USING btree (date_creation DESC);


--
-- Name: tonji_retry_payin_tentative_uniq; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX tonji_retry_payin_tentative_uniq ON public.tonji_retry USING btree (payin_id, tentative) WHERE (payin_id IS NOT NULL);


--
-- Name: tonji_retry_payout_paynala_tentative_uniq; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX tonji_retry_payout_paynala_tentative_uniq ON public.tonji_retry USING btree (payout_paynala_id, tentative) WHERE (payout_paynala_id IS NOT NULL);


--
-- Name: tonji_retry_payout_tentative_uniq; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX tonji_retry_payout_tentative_uniq ON public.tonji_retry USING btree (payout_id, tentative) WHERE (payout_id IS NOT NULL);


--
-- Name: tonji_retry_statut_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_retry_statut_idx ON public.tonji_retry USING btree (statut);


--
-- Name: tonji_signalements_cagnotte_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_signalements_cagnotte_id_idx ON public.tonji_signalements USING btree (cagnotte_id);


--
-- Name: tonji_signalements_date_creation_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_signalements_date_creation_idx ON public.tonji_signalements USING btree (date_creation DESC);


--
-- Name: tonji_signalements_project_id_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_signalements_project_id_idx ON public.tonji_signalements USING btree (project_id);


--
-- Name: tonji_signalements_statut_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX tonji_signalements_statut_idx ON public.tonji_signalements USING btree (statut);


--
-- Name: users_project_numero_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX users_project_numero_idx ON public.users USING btree (project_id, numero);


--
-- Name: tonji_admins trg_tonji_admins_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_tonji_admins_updated_at BEFORE UPDATE ON public.tonji_admins FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: tonji_cagnottes trg_tonji_cagnottes_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_tonji_cagnottes_updated_at BEFORE UPDATE ON public.tonji_cagnottes FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: tonji_payin trg_tonji_payin_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_tonji_payin_updated_at BEFORE UPDATE ON public.tonji_payin FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: tonji_payout_paynala trg_tonji_payout_paynala_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_tonji_payout_paynala_updated_at BEFORE UPDATE ON public.tonji_payout_paynala FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: tonji_payout trg_tonji_payout_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_tonji_payout_updated_at BEFORE UPDATE ON public.tonji_payout FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: tonji_signalements trg_tonji_signalements_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_tonji_signalements_updated_at BEFORE UPDATE ON public.tonji_signalements FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: users trg_users_updated_at; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER trg_users_updated_at BEFORE UPDATE ON public.users FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();


--
-- Name: tonji_admins tonji_admins_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_admins
    ADD CONSTRAINT tonji_admins_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;


--
-- Name: tonji_cagnottes tonji_cagnottes_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_cagnottes
    ADD CONSTRAINT tonji_cagnottes_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;


--
-- Name: tonji_cagnottes tonji_cagnottes_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_cagnottes
    ADD CONSTRAINT tonji_cagnottes_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: tonji_logs tonji_logs_acteur_admin_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_logs
    ADD CONSTRAINT tonji_logs_acteur_admin_id_fkey FOREIGN KEY (acteur_admin_id) REFERENCES public.tonji_admins(id) ON DELETE SET NULL;


--
-- Name: tonji_logs tonji_logs_acteur_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_logs
    ADD CONSTRAINT tonji_logs_acteur_user_id_fkey FOREIGN KEY (acteur_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tonji_logs tonji_logs_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_logs
    ADD CONSTRAINT tonji_logs_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;


--
-- Name: tonji_paiements tonji_paiements_cagnotte_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_paiements
    ADD CONSTRAINT tonji_paiements_cagnotte_id_fkey FOREIGN KEY (cagnotte_id) REFERENCES public.tonji_cagnottes(id) ON DELETE CASCADE;


--
-- Name: tonji_paiements tonji_paiements_participant_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_paiements
    ADD CONSTRAINT tonji_paiements_participant_id_fkey FOREIGN KEY (participant_id) REFERENCES public.tonji_participants(id) ON DELETE CASCADE;


--
-- Name: tonji_paiements tonji_paiements_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_paiements
    ADD CONSTRAINT tonji_paiements_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;


--
-- Name: tonji_paiements tonji_paiements_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_paiements
    ADD CONSTRAINT tonji_paiements_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tonji_participants tonji_participants_cagnotte_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_participants
    ADD CONSTRAINT tonji_participants_cagnotte_id_fkey FOREIGN KEY (cagnotte_id) REFERENCES public.tonji_cagnottes(id) ON DELETE CASCADE;


--
-- Name: tonji_participants tonji_participants_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_participants
    ADD CONSTRAINT tonji_participants_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;


--
-- Name: tonji_participants tonji_participants_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_participants
    ADD CONSTRAINT tonji_participants_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tonji_payin tonji_payin_cagnotte_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_payin
    ADD CONSTRAINT tonji_payin_cagnotte_id_fkey FOREIGN KEY (cagnotte_id) REFERENCES public.tonji_cagnottes(id);


--
-- Name: tonji_payin tonji_payin_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_payin
    ADD CONSTRAINT tonji_payin_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;


--
-- Name: tonji_payin tonji_payin_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_payin
    ADD CONSTRAINT tonji_payin_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tonji_payout tonji_payout_cagnotte_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_payout
    ADD CONSTRAINT tonji_payout_cagnotte_id_fkey FOREIGN KEY (cagnotte_id) REFERENCES public.tonji_cagnottes(id) ON DELETE RESTRICT;


--
-- Name: tonji_payout_paynala tonji_payout_paynala_cagnotte_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_payout_paynala
    ADD CONSTRAINT tonji_payout_paynala_cagnotte_id_fkey FOREIGN KEY (cagnotte_id) REFERENCES public.tonji_cagnottes(id) ON DELETE RESTRICT;


--
-- Name: tonji_payout_paynala tonji_payout_paynala_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_payout_paynala
    ADD CONSTRAINT tonji_payout_paynala_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;


--
-- Name: tonji_payout tonji_payout_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_payout
    ADD CONSTRAINT tonji_payout_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;


--
-- Name: tonji_payout tonji_payout_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_payout
    ADD CONSTRAINT tonji_payout_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: tonji_retry tonji_retry_payin_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_retry
    ADD CONSTRAINT tonji_retry_payin_id_fkey FOREIGN KEY (payin_id) REFERENCES public.tonji_payin(id) ON DELETE CASCADE;


--
-- Name: tonji_retry tonji_retry_payout_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_retry
    ADD CONSTRAINT tonji_retry_payout_id_fkey FOREIGN KEY (payout_id) REFERENCES public.tonji_payout(id) ON DELETE CASCADE;


--
-- Name: tonji_retry tonji_retry_payout_paynala_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_retry
    ADD CONSTRAINT tonji_retry_payout_paynala_id_fkey FOREIGN KEY (payout_paynala_id) REFERENCES public.tonji_payout_paynala(id) ON DELETE CASCADE;


--
-- Name: tonji_retry tonji_retry_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_retry
    ADD CONSTRAINT tonji_retry_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;


--
-- Name: tonji_signalements tonji_signalements_cagnotte_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_signalements
    ADD CONSTRAINT tonji_signalements_cagnotte_id_fkey FOREIGN KEY (cagnotte_id) REFERENCES public.tonji_cagnottes(id) ON DELETE CASCADE;


--
-- Name: tonji_signalements tonji_signalements_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_signalements
    ADD CONSTRAINT tonji_signalements_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;


--
-- Name: tonji_signalements tonji_signalements_resolu_par_admin_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_signalements
    ADD CONSTRAINT tonji_signalements_resolu_par_admin_id_fkey FOREIGN KEY (resolu_par_admin_id) REFERENCES public.tonji_admins(id) ON DELETE SET NULL;


--
-- Name: tonji_signalements tonji_signalements_signale_par_user_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.tonji_signalements
    ADD CONSTRAINT tonji_signalements_signale_par_user_id_fkey FOREIGN KEY (signale_par_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: users users_project_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_project_id_fkey FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE RESTRICT;


--
-- Name: cache; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.cache ENABLE ROW LEVEL SECURITY;

--
-- Name: cache_locks; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.cache_locks ENABLE ROW LEVEL SECURITY;

--
-- Name: failed_jobs; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.failed_jobs ENABLE ROW LEVEL SECURITY;

--
-- Name: job_batches; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.job_batches ENABLE ROW LEVEL SECURITY;

--
-- Name: jobs; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.jobs ENABLE ROW LEVEL SECURITY;

--
-- Name: migrations; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.migrations ENABLE ROW LEVEL SECURITY;

--
-- Name: personal_access_tokens; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.personal_access_tokens ENABLE ROW LEVEL SECURITY;

--
-- Name: projects; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.projects ENABLE ROW LEVEL SECURITY;

--
-- Name: projects projects_select_own; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY projects_select_own ON public.projects FOR SELECT USING ((id = public.current_project_id()));


--
-- Name: tonji_cagnottes; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.tonji_cagnottes ENABLE ROW LEVEL SECURITY;

--
-- Name: tonji_cagnottes tonji_cagnottes_all_same_project; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tonji_cagnottes_all_same_project ON public.tonji_cagnottes USING ((project_id = public.current_project_id())) WITH CHECK ((project_id = public.current_project_id()));


--
-- Name: tonji_logs; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.tonji_logs ENABLE ROW LEVEL SECURITY;

--
-- Name: tonji_logs tonji_logs_all_same_project; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tonji_logs_all_same_project ON public.tonji_logs USING ((project_id = public.current_project_id())) WITH CHECK ((project_id = public.current_project_id()));


--
-- Name: tonji_paiements; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.tonji_paiements ENABLE ROW LEVEL SECURITY;

--
-- Name: tonji_paiements tonji_paiements_all_same_project; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tonji_paiements_all_same_project ON public.tonji_paiements USING ((project_id = public.current_project_id())) WITH CHECK ((project_id = public.current_project_id()));


--
-- Name: tonji_paiements_en_attente; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.tonji_paiements_en_attente ENABLE ROW LEVEL SECURITY;

--
-- Name: tonji_participants; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.tonji_participants ENABLE ROW LEVEL SECURITY;

--
-- Name: tonji_participants tonji_participants_all_same_project; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tonji_participants_all_same_project ON public.tonji_participants USING ((project_id = public.current_project_id())) WITH CHECK ((project_id = public.current_project_id()));


--
-- Name: tonji_payin; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.tonji_payin ENABLE ROW LEVEL SECURITY;

--
-- Name: tonji_payin tonji_payin_all_same_project; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tonji_payin_all_same_project ON public.tonji_payin USING ((project_id = public.current_project_id())) WITH CHECK ((project_id = public.current_project_id()));


--
-- Name: tonji_payout; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.tonji_payout ENABLE ROW LEVEL SECURITY;

--
-- Name: tonji_payout tonji_payout_all_same_project; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tonji_payout_all_same_project ON public.tonji_payout USING ((project_id = public.current_project_id())) WITH CHECK ((project_id = public.current_project_id()));


--
-- Name: tonji_payout_paynala; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.tonji_payout_paynala ENABLE ROW LEVEL SECURITY;

--
-- Name: tonji_payout_paynala tonji_payout_paynala_all_same_project; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tonji_payout_paynala_all_same_project ON public.tonji_payout_paynala USING ((project_id = public.current_project_id())) WITH CHECK ((project_id = public.current_project_id()));


--
-- Name: tonji_project_config; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.tonji_project_config ENABLE ROW LEVEL SECURITY;

--
-- Name: tonji_retry; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.tonji_retry ENABLE ROW LEVEL SECURITY;

--
-- Name: tonji_retry tonji_retry_all_same_project; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tonji_retry_all_same_project ON public.tonji_retry USING ((project_id = public.current_project_id())) WITH CHECK ((project_id = public.current_project_id()));


--
-- Name: tonji_signalements; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.tonji_signalements ENABLE ROW LEVEL SECURITY;

--
-- Name: tonji_signalements tonji_signalements_all_same_project; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY tonji_signalements_all_same_project ON public.tonji_signalements USING ((project_id = public.current_project_id())) WITH CHECK ((project_id = public.current_project_id()));


--
-- Name: tonji_whatsapp_logs; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.tonji_whatsapp_logs ENABLE ROW LEVEL SECURITY;

--
-- Name: users; Type: ROW SECURITY; Schema: public; Owner: -
--

ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;

--
-- Name: users users_select_same_project; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY users_select_same_project ON public.users FOR SELECT USING ((project_id = public.current_project_id()));


--
-- Name: users users_update_self; Type: POLICY; Schema: public; Owner: -
--

CREATE POLICY users_update_self ON public.users FOR UPDATE USING ((id = auth.uid())) WITH CHECK (((id = auth.uid()) AND (project_id = public.current_project_id())));


--
-- PostgreSQL database dump complete
--



-- ===== Données de référence (config + tracking migrations) =====
--
-- PostgreSQL database dump
--


-- Dumped from database version 17.6
-- Dumped by pg_dump version 18.4

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.migrations (id, migration, batch) VALUES (1, '0001_01_01_000001_create_cache_table', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (2, '0001_01_01_000002_create_jobs_table', 1);
INSERT INTO public.migrations (id, migration, batch) VALUES (4, '2026_05_11_103454_create_personal_access_tokens_table', 2);
INSERT INTO public.migrations (id, migration, batch) VALUES (5, '2026_05_12_140000_drop_users_auth_fk_for_test', 3);
INSERT INTO public.migrations (id, migration, batch) VALUES (6, '2026_05_15_100000_add_total_a_envoyer_to_tondo_cagnottes', 4);
INSERT INTO public.migrations (id, migration, batch) VALUES (7, '2026_05_19_000000_create_tondo_project_config_table', 4);
INSERT INTO public.migrations (id, migration, batch) VALUES (8, '2026_05_19_100000_alter_tondo_project_config_add_operator', 5);
INSERT INTO public.migrations (id, migration, batch) VALUES (9, '2026_05_19_200000_alter_tondo_project_config_tranches', 6);
INSERT INTO public.migrations (id, migration, batch) VALUES (10, '2026_05_19_300000_tondo_project_config_tranches_add_min', 7);
INSERT INTO public.migrations (id, migration, batch) VALUES (11, '2026_05_19_400000_tondo_project_config_add_prefixes_indicatif', 8);
INSERT INTO public.migrations (id, migration, batch) VALUES (12, '2026_05_19_401000_tondo_users_add_operateur_pays_indicatif', 8);
INSERT INTO public.migrations (id, migration, batch) VALUES (13, '2026_05_19_402000_tondo_project_config_add_actif', 9);
INSERT INTO public.migrations (id, migration, batch) VALUES (14, '2026_05_20_100000_add_nombre_inscrits_to_tondo_cagnottes', 10);
INSERT INTO public.migrations (id, migration, batch) VALUES (15, '2026_06_10_133504_create_tondo_paiements_en_attente_table', 11);
INSERT INTO public.migrations (id, migration, batch) VALUES (16, '2026_06_25_120000_add_visibilite_to_tondo_cagnottes', 12);
INSERT INTO public.migrations (id, migration, batch) VALUES (17, '2026_06_25_120000_add_visibilite_to_tondo_cagnottes', 13);


--
-- Data for Name: projects; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.projects (id, slug, nom, prefixe, created_at) VALUES ('e70dd2f0-7a9e-43e0-83a1-d9adac32dcf6', 'tonji', 'Tonji', 'tonji_', '2026-05-11 10:14:05.578478+00');


--
-- Data for Name: tonji_admins; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.tonji_admins (id, project_id, email, password_hash, nom, prenom, role, actif, derniere_connexion, remember_token, created_at, updated_at) VALUES ('2ca1e763-4a6b-4525-b6c8-8d5a5be0c72a', 'e70dd2f0-7a9e-43e0-83a1-d9adac32dcf6', 'daniel@paynala.com', '$2y$12$Bnu6ZoVicSzW10N4ielmNOrlvl48eKxk0V.GJu8fV5AoQpfUF.ZuK', 'Doviakon', 'Daniel', 'super_admin', true, '2026-06-22 14:19:34+00', NULL, '2026-05-11 10:34:23.220105+00', '2026-06-22 14:19:34.057151+00');


--
-- Data for Name: tonji_project_config; Type: TABLE DATA; Schema: public; Owner: -
--

INSERT INTO public.tonji_project_config (id, project_id, commission_paynala, plafond_par_envoi, plafond_journalier, created_at, updated_at, operateur, pays, tranches, indicatif, prefixes, actif, logo) VALUES ('e730930f-ff57-4a48-96b2-b275a18f29dc', 'e70dd2f0-7a9e-43e0-83a1-d9adac32dcf6', 0.0000, 500000, 2500000, '2026-05-19 17:25:31', '2026-05-21 12:17:37', 'airtel', 'GA', '[{"type":"pourcentage","valeur":0.03,"montant_min":1,"montant_max":166667},{"type":"forfait","valeur":5000,"montant_min":166668,"montant_max":500000}]', '241', '["077","076","074"]', true, 'data:image/webp;base64,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');
INSERT INTO public.tonji_project_config (id, project_id, commission_paynala, plafond_par_envoi, plafond_journalier, created_at, updated_at, operateur, pays, tranches, indicatif, prefixes, actif, logo) VALUES ('1571b06e-5c53-4e0a-bc90-c0a1cbfc7996', 'e70dd2f0-7a9e-43e0-83a1-d9adac32dcf6', 0.0000, 1000000, 3000000, '2026-05-21 08:20:20', '2026-05-21 12:17:49', 'moov', 'GA', '[{"type":"forfait","valeur":0,"montant_min":10,"montant_max":100},{"type":"forfait","valeur":50,"montant_min":1001,"montant_max":5000},{"type":"forfait","valeur":100,"montant_min":5001,"montant_max":10000},{"type":"forfait","valeur":150,"montant_min":10001,"montant_max":20000},{"type":"forfait","valeur":200,"montant_min":20001,"montant_max":30000},{"type":"forfait","valeur":250,"montant_min":30001,"montant_max":40000},{"type":"forfait","valeur":300,"montant_min":40001,"montant_max":50000},{"type":"forfait","valeur":350,"montant_min":50001,"montant_max":60000},{"type":"forfait","valeur":400,"montant_min":60001,"montant_max":70000},{"type":"forfait","valeur":450,"montant_min":70001,"montant_max":80000},{"type":"forfait","valeur":500,"montant_min":80001,"montant_max":90000},{"type":"forfait","valeur":550,"montant_min":90001,"montant_max":100000},{"type":"forfait","valeur":600,"montant_min":100001,"montant_max":150000},{"type":"forfait","valeur":650,"montant_min":150001,"montant_max":160000},{"type":"forfait","valeur":700,"montant_min":160001,"montant_max":250000},{"type":"forfait","valeur":750,"montant_min":250001,"montant_max":300000},{"type":"forfait","valeur":800,"montant_min":300001,"montant_max":350000},{"type":"forfait","valeur":850,"montant_min":350001,"montant_max":400000},{"type":"forfait","valeur":900,"montant_min":400001,"montant_max":450000},{"type":"forfait","valeur":1000,"montant_min":450001,"montant_max":500000},{"type":"forfait","valeur":1000,"montant_min":500001,"montant_max":null}]', '241', '["062","065","066"]', true, 'data:image/webp;base64,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');


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 17, true);


--
-- PostgreSQL database dump complete
--


