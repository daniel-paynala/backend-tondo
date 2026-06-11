-- Migration 011 — certification de majorité
-- Remplace la vérification d'âge par date de naissance
-- par une certification explicite à l'inscription.
--
-- date_naissance reste NOT NULL pour rétrocompatibilité ;
-- les nouveaux comptes reçoivent le placeholder '2000-01-01'.

ALTER TABLE public.users
  ADD COLUMN IF NOT EXISTS certifie_majeur boolean NOT NULL DEFAULT false;

-- Les utilisateurs existants avec un vrai profil sont déjà vérifiés.
UPDATE public.users
  SET certifie_majeur = true
  WHERE date_naissance <> '1900-01-01';
