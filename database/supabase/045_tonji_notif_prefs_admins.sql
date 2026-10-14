-- ============================================================================
-- 045 — Préférences de notification des administrateurs (PRODUCTION, tonji_)
--
-- La colonne MANQUAIT, et personne ne s'en était aperçu : le modèle lit
-- `notif_prefs` et retombe sur ses défauts quand elle est absente, si bien que
-- tout le monde recevait tout — et que l'écran « Préférences » du dashboard
-- échouait silencieusement à l'enregistrement (42703).
--
-- Elle devient nécessaire maintenant : le rapport d'activité quotidien arrive
-- même quand tout va bien, et un courriel quotidien dont on ne peut pas se
-- désabonner finit par être filtré — avec les alertes qui l'accompagnent.
--
-- NULL = toutes les notifications activées. C'est le modèle qui le résout
-- (TondoAdmin::DEFAULT_NOTIF_PREFS), donc aucune ligne existante à rattraper.
--
-- Idempotent : rejouable sans dommage.
-- ============================================================================

ALTER TABLE public.tonji_admins
  ADD COLUMN IF NOT EXISTS notif_prefs jsonb;

COMMENT ON COLUMN public.tonji_admins.notif_prefs IS
  'Préférences d''e-mails système : {"signalements":true,"problemes":true,"rapports":true,"autre":true}. NULL = tout activé.';
