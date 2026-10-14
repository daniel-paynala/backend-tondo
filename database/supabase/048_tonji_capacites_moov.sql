-- ============================================================================
-- 048 — Moov ne sait pas ce qu'on lui prête
--
-- Le 046 a donné TOUTES les capacités aux lignes existantes, airtel/GA comme
-- moov/GA. C'était volontaire : ouvrir un pays ne devait pas fermer celui qui
-- tourne. Mais appliqué à moov, ce réglage affirme six choses fausses, et deux
-- d'entre elles se retournent contre l'utilisateur.
--
-- CE QUE LE CODE FAIT RÉELLEMENT POUR MOOV, aujourd'hui :
--
--  – KYC : aucun. `VerificationNumeroRetrait` le dit en propres termes — « Moov
--    ne propose aucun KYC » — et rend `kycOk: null`. Or avec `kyc: true`, le
--    signup appelle quand même l'API KYC d'AIRTEL avec un numéro Moov. Elle
--    répond « pas de compte », et l'inscription est refusée sur un message
--    faux : « Ce numéro ne possède pas de compte Mobile Money actif. »
--    C'est l'inverse de la règle du 2026-10-14, qui veut qu'un opérateur sans
--    KYC laisse passer avec e-mail obligatoire et OTP par les deux canaux.
--
--  – Encaissement : aucun non plus. Toute cotisation d'un numéro non-Airtel
--    part dans `storeMock`, qui crédite la collecte et marque le participant
--    « payé » SANS aucun appel de paiement. Le déclarer `payin: true` donne au
--    registre l'exact contraire de la vérité.
--
--  – Décaissement, frais, plafonds : rien d'intégré ni de tarifé pour Moov.
--
-- CE QUE CE FICHIER NE FAIT PAS. Il ne ferme pas le Gabon : les capacités d'un
-- pays sont le CUMUL de ses opérateurs, et airtel/GA sait tout faire. Le pays
-- continue donc d'encaisser et de reverser exactement comme avant. Ce fichier
-- ne touche pas non plus au mock : celui-ci est gardé par un test sur
-- « est-ce Airtel », pas par le registre. Le fermer est une décision à part —
-- la revue Apple s'inscrit avec un numéro fictif, et c'est peut-être ce
-- chemin-là qui la laisse cotiser.
--
-- Ce qui change concrètement : un numéro Moov peut s'inscrire, avec e-mail
-- obligatoire et code envoyé par SMS et par mail.
--
-- Idempotent : rejouable sans dommage. Sans ligne moov, c'est un no-op.
-- ============================================================================

UPDATE public.tonji_project_config
   SET capacites = '{
         "kyc": false,
         "verification_numero": false,
         "payin": false,
         "payout": false,
         "frais": false,
         "plafonds": false
       }'::jsonb
 WHERE lower(operateur) = 'moov';

-- Contrôle : ce que chaque intégration déclare savoir faire, après coup.
SELECT operateur, pays, actif, capacites
  FROM public.tonji_project_config
 ORDER BY pays, operateur;
