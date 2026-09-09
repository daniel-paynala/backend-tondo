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
-- dépassement du plafond de 10 M FCFA (`tonji_plafond_demandes`).
--
-- La table `tonji_organisation_documents` est VOLONTAIREMENT CONSERVÉE : elle
-- n'est plus ni écrite ni lue, mais resservira si des pièces sont exigées à
-- l'appui d'une demande de dépassement. Aucune donnée n'est supprimée.
--
-- ⚠️ PROD (`tonji_`). En DEV, remplacer par `tondo_`. IDEMPOTENT.
-- ============================================================================

-- Nouveau défaut : approuvée dès la création.
ALTER TABLE public.tonji_organisations
  ALTER COLUMN statut SET DEFAULT 'approuve';

-- Les dossiers restés en attente n'ont plus de raison de l'être : personne ne
-- viendra les instruire, et leurs titulaires seraient bloqués hors de l'app.
-- Les statuts 'rejete' et 'suspendu' ne sont PAS touchés : ce sont des
-- décisions de modération, pas des dossiers en cours d'instruction.
UPDATE public.tonji_organisations
   SET statut = 'approuve', updated_at = now()
 WHERE statut = 'en_attente';

-- ============================================================================
-- FIN 024_associations_sans_dossier.sql
-- ============================================================================
