-- ============================================================================
-- 044 — Suspension d'un utilisateur + quota de collectes (PRODUCTION, tonji_)
--
-- DEUX CHANGEMENTS, liés par le même objet : tenir un organisateur.
--
-- 1. SUSPENSION. Un utilisateur suspendu ne collecte plus, ne transfère plus
--    et ne paie plus de commerce — sur TOUTES les collectes qu'il organise, et
--    sur tous les canaux. La suspension ne touche PAS sa connexion : il doit
--    pouvoir ouvrir l'app, voir son argent et joindre le support. Lui fermer
--    la porte pendant qu'on retient ses fonds serait l'inverse de ce qu'on
--    veut.
--
--    `suspendu_at` plutôt qu'un booléen : savoir DEPUIS QUAND vaut mieux que
--    savoir que oui, et le motif part avec, pour que celui qui reprend le
--    dossier n'ait pas à deviner.
--
-- 2. QUOTA. Nombre de collectes actives qu'un même organisateur peut tenir.
--    Réglable côté serveur, parce qu'une limite qui ne se change pas n'est pas
--    une limite, c'est une impasse. NULL = la valeur du code (5).
--
-- Idempotent : rejouable sans dommage.
-- ============================================================================

ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS suspendu_at    timestamptz,
  ADD COLUMN IF NOT EXISTS suspendu_motif text;

COMMENT ON COLUMN public.users.suspendu_at IS
  'Date de suspension de l''organisateur. Non NULL = cotisations, transferts et paiements marchands verrouillés sur toutes ses collectes. N''empêche pas la connexion.';
COMMENT ON COLUMN public.users.suspendu_motif IS
  'Raison de la suspension, pour le dossier interne. Jamais affichée au cotisant.';

ALTER TABLE public.tonji_project_config
  ADD COLUMN IF NOT EXISTS max_cagnottes_actives smallint;

COMMENT ON COLUMN public.tonji_project_config.max_cagnottes_actives IS
  'Collectes actives par organisateur. NULL = valeur par défaut du code (5).';
