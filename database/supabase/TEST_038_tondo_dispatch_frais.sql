-- ============================================================================
-- TEST_038_tondo_dispatch_frais.sql
-- Répartition des frais : à qui va chaque part, et combien.
--
-- Un transfert devient PLUSIEURS décaissements. Pour 100 000 FCFA à 3 % de
-- frais répartis en Airtel 1 % / Paynala 0,5 % / Custom 1,5 % :
--
--   1 000 FCFA  -> numéro du compte « Airtel »
--     500 FCFA  -> numéro du compte « Paynala »
--   1 500 FCFA  -> numéro du compte « Custom »
--  97 000 FCFA  -> numéro choisi par le client
--  -----------
-- 100 000 FCFA  débités de la collecte
--
-- Les frais sont **retenus sur le montant**, pas ajoutés : la collecte est
-- débitée de ce que le client a saisi, le bénéficiaire reçoit le reste.
--
-- ── Deux tables, et c'est nécessaire ────────────────────────────────────────
--
-- `tondo_frais_comptes` est le RÉGLAGE : les comptes et leurs parts.
-- `tondo_frais_dus`     est le JOURNAL : ce que chaque part doit, et si c'est
--                        parti.
--
-- Le journal n'est pas un luxe. L'API de décaissement n'accepte qu'un seul
-- `msisdn` par appel et impose un montant plancher : 1 % de 3 000 FCFA fait
-- 30 FCFA, qui ne peuvent PAS partir seuls. Une part sous le plancher est donc
-- enregistrée comme due et s'accumule, puis part dès que le cumul du compte
-- passe le seuil. Sans journal, ces francs disparaîtraient.
--
-- Il sert aussi aux échecs partiels, qui deviennent la norme avec un dispatch :
-- le bénéficiaire passe, la part Paynala tombe en timeout. La part reste alors
-- `du` et sera réessayée — **jamais** en reprenant l'argent du client.
--
-- ── Inerte par défaut ───────────────────────────────────────────────────────
--
-- Aucun compte configuré = aucune part = comportement identique à aujourd'hui.
-- Pas de drapeau à basculer : la fonction s'active en remplissant le dashboard,
-- et le taux ne devient effectif que lorsque Daniel l'a voulu.
--
-- ⚠️ MIROIR TEST (`tondo_`) du 038. La PROD joue `038_tonji_dispatch_frais.sql`.
-- IDEMPOTENT. À jouer dans le SQL Editor Supabase.
-- ============================================================================

-- ── 1. Comptes de frais et leurs parts ──────────────────────────────────────

CREATE TABLE IF NOT EXISTS public.tondo_frais_comptes (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id    uuid NOT NULL REFERENCES public.projects(id) ON DELETE CASCADE,

  -- Service concerné. Les deux sorties d'argent n'ont aucune raison de
  -- partager la même répartition : un paiement marchand et un transfert à un
  -- particulier ne rémunèrent pas les mêmes acteurs.
  service       varchar(20) NOT NULL,

  -- Nom affiché au dashboard et dans les journaux (« Airtel », « Paynala »…).
  libelle       varchar(80) NOT NULL,

  -- Numéro qui reçoit la part, en E.164 comme partout ailleurs.
  numero_tel    varchar(20) NOT NULL,

  -- Grade du compte receveur, pour le routage B2C/B2B de l'API. Se tromper
  -- fait répondre « Transaction Ambiguous » et débite pour rien.
  type_paynala  varchar(20) NOT NULL DEFAULT 'particulier',

  -- Part prélevée, en DÉCIMAL : 0.0100 = 1 %. Même échelle que
  -- tondo_project_config.frais_marchand et tondo_marchands.frais_taux.
  taux          numeric(6,4) NOT NULL,

  -- Désactiver plutôt que supprimer : le journal garde des lignes qui
  -- pointent vers ce compte, et un historique doit rester lisible.
  actif         boolean NOT NULL DEFAULT true,

  created_at    timestamptz NOT NULL DEFAULT now(),
  updated_at    timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE public.tondo_frais_comptes IS
  'Répartition des frais : quel numéro reçoit quelle part, par service. Aucune ligne = aucun dispatch.';
COMMENT ON COLUMN public.tondo_frais_comptes.taux IS
  'Part prélevée, en décimal (0.01 = 1 %). La somme des lignes actives d''un service EST le taux de ce service.';

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_frais_comptes_service_check') THEN
    ALTER TABLE public.tondo_frais_comptes ADD CONSTRAINT tondo_frais_comptes_service_check
      CHECK (service IN ('transfert', 'marchand'));
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_frais_comptes_type_check') THEN
    ALTER TABLE public.tondo_frais_comptes ADD CONSTRAINT tondo_frais_comptes_type_check
      CHECK (type_paynala IN ('particulier', 'entreprise'));
  END IF;

  -- Borne haute par LIGNE. Elle écarte la faute de frappe « 1 » pour 1 %, qui
  -- prélèverait la totalité du transfert. La somme des lignes est contrôlée
  -- côté application, qui peut expliquer le refus.
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_frais_comptes_taux_check') THEN
    ALTER TABLE public.tondo_frais_comptes ADD CONSTRAINT tondo_frais_comptes_taux_check
      CHECK (taux >= 0 AND taux <= 0.25);
  END IF;
END $$;

-- Un même numéro ne peut pas porter deux parts du même service : on ne saurait
-- plus laquelle appliquer, et le cumul serait silencieux.
CREATE UNIQUE INDEX IF NOT EXISTS tondo_frais_comptes_unique_numero
  ON public.tondo_frais_comptes (project_id, service, numero_tel);

CREATE INDEX IF NOT EXISTS tondo_frais_comptes_service_idx
  ON public.tondo_frais_comptes (project_id, service) WHERE actif;


-- ── 2. Journal des parts dues ───────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS public.tondo_frais_dus (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  project_id    uuid NOT NULL REFERENCES public.projects(id) ON DELETE CASCADE,

  -- Compte bénéficiaire de la part. ON DELETE RESTRICT : on ne supprime pas un
  -- compte qui doit encore de l'argent — d'où la colonne `actif` plus haut.
  compte_id     uuid NOT NULL REFERENCES public.tondo_frais_comptes(id) ON DELETE RESTRICT,

  -- Reversement principal qui a généré cette part. Permet de remonter du franc
  -- prélevé à la transaction du client.
  payout_id     uuid REFERENCES public.tondo_payout(id) ON DELETE SET NULL,
  cagnotte_id   uuid REFERENCES public.tondo_cagnottes(id) ON DELETE SET NULL,

  montant       integer NOT NULL,

  --   'du'    : enregistré, pas encore décaissé (souvent sous le plancher).
  --   'regle' : décaissé avec succès, `trans_id` renseigné.
  --   'echec' : refus AFFIRMÉ de l'opérateur. L'argent n'est pas parti et la
  --             part reste à notre charge — à reprendre à la main.
  --   'incertain' : issue inconnue. On ne réessaie PAS tout seul : relancer
  --             pourrait payer deux fois la même part.
  statut        varchar(20) NOT NULL DEFAULT 'du',

  trans_id      varchar(40),
  response      jsonb,

  created_at    timestamptz NOT NULL DEFAULT now(),
  updated_at    timestamptz NOT NULL DEFAULT now(),
  regle_at      timestamptz
);

COMMENT ON TABLE public.tondo_frais_dus IS
  'Journal des parts de frais : ce que chaque compte doit recevoir, et si c''est parti. Permet l''accumulation sous le plancher de décaissement et la reprise des échecs.';

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_frais_dus_statut_check') THEN
    ALTER TABLE public.tondo_frais_dus ADD CONSTRAINT tondo_frais_dus_statut_check
      CHECK (statut IN ('du', 'regle', 'echec', 'incertain'));
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_frais_dus_montant_check') THEN
    ALTER TABLE public.tondo_frais_dus ADD CONSTRAINT tondo_frais_dus_montant_check
      CHECK (montant > 0);
  END IF;

  -- Un règlement sans référence de transaction serait introuvable chez
  -- l'opérateur : les deux vont ensemble.
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tondo_frais_dus_regle_check') THEN
    ALTER TABLE public.tondo_frais_dus ADD CONSTRAINT tondo_frais_dus_regle_check
      CHECK (statut <> 'regle' OR trans_id IS NOT NULL);
  END IF;
END $$;

-- Index NON unique, et c'est voulu : un seul décaissement règle PLUSIEURS
-- parts dues du même compte. C'est tout l'intérêt de l'accumulation — 1 % de
-- 3 000 FCFA fait 30 FCFA, sous le plancher de l'opérateur, et ces parts ne
-- partent qu'une fois cumulées. Elles partagent donc la référence du
-- décaissement qui les a réglées.
--
-- L'unicité vis-à-vis de l'opérateur ne vient pas d'ici mais de la génération
-- de la référence elle-même (suffixe aléatoire sur un alphabet de 32 signes).
CREATE INDEX IF NOT EXISTS tondo_frais_dus_trans_id_idx
  ON public.tondo_frais_dus (trans_id) WHERE trans_id IS NOT NULL;

-- Index du règlement : « que doit-on à chaque compte ? » est la question posée
-- à chaque passage du job.
-- « Que doit-on à ce compte ? » est la question posée à chaque passage du job.
-- `trans_id IS NULL` distingue les parts libres de celles déjà prises par un
-- règlement en cours : la prise est atomique, elle pose la référence avant
-- l'appel à l'opérateur.
CREATE INDEX IF NOT EXISTS tondo_frais_dus_a_regler_idx
  ON public.tondo_frais_dus (project_id, compte_id) WHERE statut = 'du';

CREATE INDEX IF NOT EXISTS tondo_frais_dus_payout_idx
  ON public.tondo_frais_dus (payout_id);


-- ============================================================================
-- FIN TEST_038_tondo_dispatch_frais.sql
-- ============================================================================
