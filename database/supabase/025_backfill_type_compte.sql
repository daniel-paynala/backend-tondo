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
