-- ============================================================================
-- 040_tonji_cumul_cotisations.sql
-- Un compteur de ce qui a été COLLECTÉ, distinct de ce qui reste.
--
-- ── Pourquoi ────────────────────────────────────────────────────────────────
--
-- La gratuité du reversement se juge sur ce qu'une cagnotte a reçu, pas sur ce
-- qu'elle contient encore. `montant_collecte` ne peut pas servir : c'est un
-- SOLDE, décrémenté à chaque sortie (`SortieArgent`, `ReversementService`).
-- L'utiliser rendrait la gratuité réversible — il suffirait de reverser pour
-- repasser sous le seuil et redevenir éligible.
--
--   cumul_cotisations   somme des cotisations reçues depuis la création.
--                       Ne baisse JAMAIS. Une restitution de décaissement
--                       refusé n'en est pas une : elle ne l'incrémente pas.
--
-- ── Et ce que le reversement aurait dû coûter ───────────────────────────────
--
--   frais_attendus      frais calculés par `FraisSortie` au moment du
--                       décaissement, en FCFA. Enregistré pour la
--                       réconciliation ; il ne modifie pas le montant envoyé,
--                       que Paynala continue de traiter.
--
-- Idempotent : rejouable sans effet de bord.
-- ============================================================================

ALTER TABLE public.tonji_cagnottes
  ADD COLUMN IF NOT EXISTS cumul_cotisations bigint NOT NULL DEFAULT 0;

COMMENT ON COLUMN public.tonji_cagnottes.cumul_cotisations IS
  'Somme des cotisations reçues depuis la création. Ne baisse jamais — distinct de montant_collecte, qui est le solde.';

ALTER TABLE public.tonji_cagnottes
  DROP CONSTRAINT IF EXISTS tonji_cagnottes_cumul_cotisations_positif;
ALTER TABLE public.tonji_cagnottes
  ADD CONSTRAINT tonji_cagnottes_cumul_cotisations_positif
  CHECK (cumul_cotisations >= 0);

ALTER TABLE public.tonji_payout
  ADD COLUMN IF NOT EXISTS frais_attendus integer;

COMMENT ON COLUMN public.tonji_payout.frais_attendus IS
  'Frais que le barème Tonji prévoyait pour ce reversement, en FCFA. Indicatif : le montant envoyé n''est pas diminué.';

-- ── Reprise de l'existant ───────────────────────────────────────────────────
--
-- `tonji_paiements` ne porte que des cotisations abouties : la ligne n'est
-- écrite qu'après le claim atomique du payin. Seul `actif` est à filtrer — il
-- écarte les doublons neutralisés par le correctif du 2026-08-31.
--
-- Le WHERE final rend la reprise rejouable : une cagnotte déjà comptée n'est
-- pas recalculée, et un compteur ajusté à la main n'est pas écrasé.
UPDATE public.tonji_cagnottes c
   SET cumul_cotisations = COALESCE((
         SELECT SUM(p.montant)
           FROM public.tonji_paiements p
          WHERE p.cagnotte_id = c.id
            AND COALESCE(p.actif, true) = true
       ), 0)
 WHERE c.cumul_cotisations = 0;
