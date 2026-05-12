-- ============================================================================
--  Tondo — Seed de démo (idempotent)
--
--  Insère un jeu de données réaliste pour visualiser le dashboard sans
--  attendre les premiers vrais utilisateurs. À exécuter APRÈS le 005.
--
--  Stratégie :
--   - public.users normalement contraint à auth.users (FK). Pour seeder sans
--     créer des comptes auth.users réels, on bascule temporairement la FK
--     en NOT VALID le temps des inserts puis on remet la contrainte.
--   - Tous les inserts utilisent ON CONFLICT DO NOTHING pour rester idempotents.
--   - Mot de passe des 3 admins seedés = « Demo123! »
--   - Phone des users seedés = +241 fictifs (non confirmés côté Supabase Auth).
-- ============================================================================

-- ----------------------------------------------------------------------------
--  1. Admins additionnels (Daniel existe déjà via 004)
-- ----------------------------------------------------------------------------
insert into public.tondo_admins (project_id, email, password_hash, nom, prenom, role)
select
  (select id from public.projects where slug = 'tondo'),
  v.email,
  '$2y$12$EUszXbjFJbA2.URUPhDJseu4aHR1ugLWDJRK7OIvs3.xHDZuEUGMK', -- Demo123!
  v.nom,
  v.prenom,
  v.role::public.tondo_role_admin
from (values
  ('fidele@paynala.com', 'Fidele', 'Bertrand', 'admin'),
  ('marie@paynala.com', 'Operateur', 'Marie', 'operateur'),
  ('compta@paynala.com', 'Lecteur', 'Compta', 'lecteur')
) as v(email, nom, prenom, role)
on conflict (email) do nothing;

-- ----------------------------------------------------------------------------
--  2. Désactive temporairement la FK public.users.id → auth.users.id
--  pour permettre l'insertion de users sans entrée auth.users associée.
-- ----------------------------------------------------------------------------
alter table public.users drop constraint if exists users_id_fkey;

-- ----------------------------------------------------------------------------
--  3. Bloc de seed des utilisateurs + cagnottes + participants + paiements +
--  transactions + signalements + logs. Variables locales pour les UUIDs.
-- ----------------------------------------------------------------------------
do $$
declare
  proj_id uuid;
  daniel_admin_id uuid;
  fidele_admin_id uuid;

  u_mboula uuid := gen_random_uuid();
  u_eyenga uuid := gen_random_uuid();
  u_koumba uuid := gen_random_uuid();
  u_nkoulou uuid := gen_random_uuid();
  u_bekale uuid := gen_random_uuid();
  u_mba uuid := gen_random_uuid();
  u_ondo uuid := gen_random_uuid();

  c_mboula uuid := gen_random_uuid();
  c_anniv uuid := gen_random_uuid();
  c_akanda uuid := gen_random_uuid();
  c_mariage uuid := gen_random_uuid();
  c_bureau uuid := gen_random_uuid();

  p1 uuid; p2 uuid; p3 uuid; p4 uuid; p5 uuid; p6 uuid;
begin
  select id into proj_id from public.projects where slug = 'tondo';
  select id into daniel_admin_id from public.tondo_admins where email = 'daniel@paynala.com';
  select id into fidele_admin_id from public.tondo_admins where email = 'fidele@paynala.com';

  -- ── Users ─────────────────────────────────────────────────────────────
  insert into public.users (id, project_id, nom, prenom, date_naissance, numero, type_client, kyc_valide, created_at)
  values
    (u_mboula, proj_id, 'Mboula', 'Sylvain', '1978-03-12', '+241 77 12 34 12', 'particulier', true, now() - interval '50 days'),
    (u_eyenga, proj_id, 'Eyenga', 'Solange', '1985-09-22', '+241 77 12 34 88', 'particulier', true, now() - interval '85 days'),
    (u_koumba, proj_id, 'Koumba', 'Jean', '1990-11-05', '+241 77 12 34 17', 'particulier', false, now() - interval '3 days'),
    (u_nkoulou, proj_id, 'Nkoulou', 'Sébastien', '1982-06-30', '+241 77 12 34 65', 'marchand', true, now() - interval '101 days'),
    (u_bekale, proj_id, 'Bekale', 'Marina', '1988-02-14', '+241 77 12 34 09', 'particulier', true, now() - interval '39 days'),
    (u_mba, proj_id, 'Mba', 'Léontine', '1975-12-01', '+241 77 12 34 23', 'entreprise', true, now() - interval '54 days'),
    (u_ondo, proj_id, 'Ondo', 'Joachim', '1992-07-18', '+241 77 12 34 81', 'particulier', true, now() - interval '93 days')
  on conflict (id) do nothing;

  -- ── Cagnottes ─────────────────────────────────────────────────────────
  insert into public.tondo_cagnottes (
    id, reference, project_id, user_id, titre, type, statut,
    montant_collecte, montant_beneficiaire, montant_avec_frais,
    nombre_participants, nombre_splits, nombre_envois,
    montant_par_cycle, periodicite, intervalle, jour_mois,
    numero_retrait_masque, date_creation
  )
  values
    (c_mboula, '12480', proj_id, u_mboula, 'Tontine famille Mboula', 'tontine_periodique', 'active',
      875000, 900000, 945000, 12, 2, 2, 75000, 'mensuelle', 1, 5,
      '+241 XX XX XX 12', now() - interval '120 days'),
    (c_anniv, '12331', proj_id, u_eyenga, 'Anniversaire Maman', 'cagnotte_ouverte', 'active',
      342500, 500000, 525000, 28, 1, 1, null, null, 1, null,
      '+241 XX XX XX 88', now() - interval '30 days'),
    (c_akanda, '12150', proj_id, u_nkoulou, 'Caisse de quartier Akanda', 'tontine_periodique', 'active',
      1240000, 1240000, 1302000, 20, 3, 4, 62000, 'hebdomadaire', 1, null,
      '+241 XX XX XX 65', now() - interval '189 days'),
    (c_mariage, '11987', proj_id, u_eyenga, 'Mariage Eyenga', 'cagnotte_ouverte', 'active',
      156000, 1000000, 1050000, 14, 2, 2, null, null, 1, null,
      '+241 XX XX XX 88', now() - interval '14 days'),
    (c_bureau, '11710', proj_id, u_bekale, 'Bureau collègues — projet Yangoni', 'tontine_periodique', 'active',
      410000, 410000, 430500, 6, 1, 1, 50000, 'mensuelle', 1, 28,
      '+241 XX XX XX 09', now() - interval '92 days')
  on conflict (id) do nothing;

  -- ── Participants (juste pour Mboula et Anniv) ──────────────────────────
  p1 := gen_random_uuid(); p2 := gen_random_uuid(); p3 := gen_random_uuid();
  p4 := gen_random_uuid(); p5 := gen_random_uuid(); p6 := gen_random_uuid();

  insert into public.tondo_participants (id, project_id, cagnotte_id, user_id, nom, prenom, numero_masque, statut_paiement, montant_paye)
  values
    (p1, proj_id, c_mboula, u_mboula, 'Mboula', 'Sylvain', '+241 XX XX XX 12', 'paye', 75000),
    (p2, proj_id, c_mboula, u_ondo, 'Ondo', 'Joachim', '+241 XX XX XX 81', 'paye', 75000),
    (p3, proj_id, c_mboula, u_bekale, 'Bekale', 'Marina', '+241 XX XX XX 09', 'paye', 75000),
    (p4, proj_id, c_mboula, u_mba, 'Mba', 'Léontine', '+241 XX XX XX 23', 'en_attente', 0),
    (p5, proj_id, c_anniv, u_koumba, 'Koumba', 'Jean', '+241 XX XX XX 17', 'paye', 15000),
    (p6, proj_id, c_anniv, u_eyenga, 'Eyenga', 'Solange', '+241 XX XX XX 88', 'paye', 50000);

  -- ── Historique de paiements (pour total_cotise) ────────────────────────
  insert into public.tondo_paiements (project_id, cagnotte_id, participant_id, user_id, montant, date)
  values
    (proj_id, c_mboula, p1, u_mboula, 75000, now() - interval '14 days'),
    (proj_id, c_mboula, p2, u_ondo, 75000, now() - interval '14 days 6 hours'),
    (proj_id, c_mboula, p3, u_bekale, 75000, now() - interval '13 days'),
    (proj_id, c_anniv, p5, u_koumba, 15000, now() - interval '5 days'),
    (proj_id, c_anniv, p6, u_eyenga, 50000, now() - interval '4 days'),
    (proj_id, c_anniv, p6, u_eyenga, 25000, now() - interval '2 days');

  -- ── Transactions Payin ─────────────────────────────────────────────────
  insert into public.tondo_payin (
    project_id, cagnotte_id, user_id, trans_id, operateur_id,
    numero_tel, montant, statut, request, response, date_creation
  )
  values
    (proj_id, c_mboula, u_mboula, 'TONDO-PAYIN-00012345', 'AM-2026-051114-9F8A',
      '+241 77 12 34 12', 75000, 'succes',
      '{"channel":"airtel_money","amount":75000}'::jsonb,
      '{"status":"OK","reference":"AM-2026-051114-9F8A"}'::jsonb,
      now() - interval '14 days'),
    (proj_id, c_anniv, u_eyenga, 'TONDO-PAYIN-00012346', 'AM-2026-051113-7C12',
      '+241 77 12 34 88', 50000, 'succes',
      '{"channel":"airtel_money","amount":50000}'::jsonb,
      '{"status":"OK","reference":"AM-2026-051113-7C12"}'::jsonb,
      now() - interval '4 days'),
    (proj_id, c_anniv, u_koumba, 'TONDO-PAYIN-00012347', 'MM-2026-051112-A4D9',
      '+241 77 12 34 17', 15000, 'succes',
      '{"channel":"moov_money","amount":15000}'::jsonb,
      '{"status":"OK"}'::jsonb,
      now() - interval '5 days'),
    (proj_id, c_akanda, u_nkoulou, 'TONDO-PAYIN-00012348', 'MM-2026-051110-3E8F',
      '+241 77 12 34 65', 62000, 'echec',
      '{"channel":"moov_money","amount":62000}'::jsonb,
      '{"status":"FAIL","reason":"insufficient_funds"}'::jsonb,
      now() - interval '1 day'),
    (proj_id, c_mboula, u_ondo, 'TONDO-PAYIN-00012349', null,
      '+241 77 12 34 81', 75000, 'initie',
      '{"channel":"airtel_money","amount":75000}'::jsonb,
      null,
      now() - interval '20 minutes');

  -- ── Transactions Payout ────────────────────────────────────────────────
  insert into public.tondo_payout (
    project_id, cagnotte_id, user_id, trans_id, operateur_id,
    numero_tel, montant, statut, request, response, date_creation
  )
  values
    (proj_id, c_bureau, u_bekale, 'TONDO-PAYOUT-00004520', 'AM-2026-051022-D2F5',
      '+241 77 12 34 09', 410000, 'succes',
      '{"split":1,"of":1}'::jsonb,
      '{"status":"OK"}'::jsonb,
      now() - interval '6 days'),
    (proj_id, c_mboula, u_mboula, 'TONDO-PAYOUT-00004521', 'AM-2026-051113-7C12',
      '+241 77 12 34 12', 500000, 'en_cours',
      '{"split":1,"of":2}'::jsonb, null,
      now() - interval '2 hours');

  -- ── Transactions Payout Paynala ────────────────────────────────────────
  insert into public.tondo_payout_paynala (
    project_id, cagnotte_id, trans_id, operateur_id,
    montant, statut, request, response, date_creation
  )
  values
    (proj_id, c_anniv, 'TONDO-PAYNALA-00000812', 'MM-2026-051112-A4D9',
      1000, 'succes',
      '{"commission_2pct":1000}'::jsonb,
      '{"status":"OK"}'::jsonb,
      now() - interval '4 days');

  -- ── Signalements ───────────────────────────────────────────────────────
  insert into public.tondo_signalements (
    project_id, cagnotte_id, signale_par_user_id, signale_par_libelle,
    motif, description, statut, date_creation
  )
  values
    (proj_id, c_akanda, u_mba, 'Léontine Mba',
      'fraude_suspectee'::public.tondo_motif_signalement,
      'Le gérant a changé le numéro de retrait après le premier versement.',
      'en_traitement'::public.tondo_statut_signalement,
      now() - interval '3 days'),
    (proj_id, c_mariage, u_koumba, 'Jean Koumba',
      'doublon'::public.tondo_motif_signalement,
      'Identique à une cagnotte précédente du même gérant.',
      'nouveau'::public.tondo_statut_signalement,
      now() - interval '5 hours'),
    (proj_id, c_mboula, u_nkoulou, 'Sébastien Nkoulou',
      'fraude_suspectee'::public.tondo_motif_signalement,
      'Un participant accuse le gérant de manipuler l''ordre de retrait.',
      'nouveau'::public.tondo_statut_signalement,
      now() - interval '12 hours');

  -- ── Logs ──────────────────────────────────────────────────────────────
  insert into public.tondo_logs (
    project_id, acteur_admin_id, acteur_libelle, acteur_role,
    action, cible, niveau, date
  )
  values
    (proj_id, daniel_admin_id, 'Daniel Doviakon', 'super_admin',
      'Validation KYC manuelle', 'user u-koumba (Jean Koumba)',
      'info'::public.tondo_niveau_log, now() - interval '30 minutes'),
    (proj_id, null, 'Système', 'systeme',
      'Payin succès enregistré', 'trans TONDO-PAYIN-00012345',
      'info'::public.tondo_niveau_log, now() - interval '14 days'),
    (proj_id, null, 'Système', 'systeme',
      'Payin échec après 3 retries', 'trans TONDO-PAYIN-00012348',
      'error'::public.tondo_niveau_log, now() - interval '1 day'),
    (proj_id, fidele_admin_id, 'Bertrand Fidele', 'admin',
      'Suspension utilisateur', 'user u-nkoulou (Sébastien Nkoulou)',
      'warning'::public.tondo_niveau_log, now() - interval '2 days'),
    (proj_id, null, 'Système', 'systeme',
      'Retrait cagnotte effectué', 'cagnotte 11710 — 410 000 FCFA',
      'info'::public.tondo_niveau_log, now() - interval '6 days');
end $$;

-- ----------------------------------------------------------------------------
--  4. Re-pose la FK avec NOT VALID (constrainera les futurs inserts,
--  laisse les rows de seed tranquilles).
-- ----------------------------------------------------------------------------
alter table public.users
  add constraint users_id_fkey
  foreign key (id) references auth.users(id) on delete cascade
  not valid;
