-- ============================================================================
-- 046 — Capacités par opérateur/pays + rattachement des collectes à un pays
--       (TEST, préfixe tondo_)
--
-- Préalable au lancement hors du Gabon. Le système suppose aujourd'hui partout
-- « Airtel Money Gabon » ; il doit apprendre à SAVOIR CE QU'IL NE SAIT PAS
-- FAIRE, pays par pays, et se dégrader proprement plutôt que de tomber — ou
-- pire, d'encaisser de l'argent qu'il ne pourra pas reverser.
--
-- QUATRE CHANGEMENTS.
--
-- 1. `project_config.capacites` — le registre. Pour chaque couple
--    opérateur/pays, ce que l'intégration sait faire. NULL = rien, et c'est
--    voulu : un opérateur dont personne n'a décrit les capacités n'en a aucune.
--    Supposer l'inverse ferait collecter sans pouvoir rendre.
--
--    Les lignes EXISTANTES (airtel/GA, moov/GA) reçoivent tout à vrai : ouvrir
--    un nouveau pays ne doit surtout pas fermer celui qui tourne.
--
-- 2. `users.indicatif` normalisé. Il cohabitait sous deux formes — « +241 » et
--    « 241 » — selon les comptes. Comparer des pays là-dessus échouerait une
--    fois sur deux, et dans le mauvais sens : une comparaison ratée laisse
--    passer. La forme retenue est celle de la config : SANS le plus.
--
-- 3. `users.pays` rattrapé quand le numéro permet de le déduire. Les numéros
--    manifestement factices restent sans pays — on ne devine pas.
--
-- 4. `cagnottes.pays` — une collecte appartient à un écosystème. Recopié depuis
--    son organisateur à la création, et IMMUABLE ensuite, comme le numéro de
--    retrait (RÈGLE 3) : c'est lui qui détermine qui a le droit de cotiser, il
--    ne peut pas changer après coup.
--
-- L'ORDRE COMPTE. La recopie vers les collectes vient en DERNIER, parce
-- qu'elle lit `users.pays` que l'étape 3 vient de déduire. Dans l'autre sens,
-- seules les collectes des comptes déjà rattachés recevaient un pays : toutes
-- les autres restaient nues, tenues par le seul repli sur leur organisateur.
--
-- Idempotent : rejouable sans dommage — et il FAUT le rejouer là où il a été
-- joué dans l'ancien ordre, pour que les collectes portent enfin leur pays.
-- ============================================================================

-- ── 1. Le registre des capacités ────────────────────────────────────────────
ALTER TABLE public.tondo_project_config
  ADD COLUMN IF NOT EXISTS capacites jsonb;

COMMENT ON COLUMN public.tondo_project_config.capacites IS
  'Ce que l''intégration sait faire pour ce couple opérateur/pays : kyc, verification_numero, payin, payout, frais, plafonds. NULL = aucune capacité.';

-- L'existant garde tout. Seules les lignes sans capacités décrites sont
-- touchées : rejouer ce fichier n'écrase pas un réglage fait depuis.
UPDATE public.tondo_project_config
   SET capacites = '{
         "kyc": true,
         "verification_numero": true,
         "payin": true,
         "payout": true,
         "frais": true,
         "plafonds": true
       }'::jsonb
 WHERE capacites IS NULL;

-- ── 2. Indicatif sous une seule forme ───────────────────────────────────────
-- « +241 » et « 241 » cohabitaient. On retient la forme de la config.
UPDATE public.users
   SET indicatif = ltrim(indicatif, '+')
 WHERE indicatif LIKE '+%';

COMMENT ON COLUMN public.users.indicatif IS
  'Indicatif pays SANS le plus (ex : 241), même forme que tondo_project_config.indicatif.';

-- ── 3. Pays déduit du numéro, quand c'est possible ──────────────────────────
-- Un numéro manifestement factice ne donne rien : on préfère un pays vide à un
-- pays faux, qui rattacherait un compte au mauvais écosystème.
UPDATE public.users u
   SET pays = cfg.pays
  FROM (SELECT DISTINCT indicatif, pays FROM public.tondo_project_config) cfg
 WHERE u.pays IS NULL
   AND u.numero IS NOT NULL
   AND u.numero LIKE '+' || cfg.indicatif || '%';

-- ── 4. Le pays d'une collecte ───────────────────────────────────────────────
-- EN DERNIER, et c'est tout l'enjeu de l'ordre : cette recopie lit
-- `users.pays`, que la section 3 vient seulement de déduire. Placée avant,
-- elle ne voyait que les comptes déjà rattachés et laissait toutes les autres
-- collectes sans pays — elles ne tenaient plus que par le repli sur leur
-- organisateur, au lieu de porter leur écosystème en propre.
ALTER TABLE public.tondo_cagnottes
  ADD COLUMN IF NOT EXISTS pays char(2);

COMMENT ON COLUMN public.tondo_cagnottes.pays IS
  'Écosystème de la collecte, repris de son organisateur à la création. Immuable : il détermine qui peut cotiser.';

UPDATE public.tondo_cagnottes c
   SET pays = u.pays
  FROM public.users u
 WHERE u.id = c.user_id
   AND c.pays IS NULL
   AND u.pays IS NOT NULL;

CREATE INDEX IF NOT EXISTS tondo_cagnottes_pays_idx
  ON public.tondo_cagnottes (pays);
