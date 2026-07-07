# Mise en production — base de données Tondo

Runbook de bascule vers la **base de prod**. Décisions actées (2026-07-07) :

- **Auth** : on garde le flow **Laravel + Wirepick** (OTP géré par le backend).
  → La FK `public.users → auth.users` reste **volontairement absente** en prod,
  donc la migration `2026_05_12_140000_drop_users_auth_fk_for_test` **s'applique
  aussi en prod** (aucune migration exclue côté Laravel).
- **Hébergement** : **nouveau projet Supabase prod**, séparé du dev
  (`itgjlhaalodlgwsyrjnz` = dev/test, à ne pas toucher).
- **OTP de test** : `MOBILE_TEST_OTP` **conservé**, mais actif pour le **seul
  numéro de review Apple** whitelisté.

> ⚠️ On **ne copie PAS** la base de test en entier. Les seules données reportées
> sont les données de **référence** (registry projet, config frais/opérateur,
> admins). Jamais les users / cagnottes / participants / paiements / transactions.

---

## 0. Prérequis

- Client PostgreSQL local : `pg_dump` et `psql` (`brew install libpq` puis
  ajouter au PATH, ou `brew install postgresql`).
- Accès au dashboard Supabase (compte Paynala).

## 1. Créer le projet Supabase prod

1. Supabase → **New project** (région proche du Gabon, ex. `eu-west`).
2. Noter / récupérer :
   - **Connection string** (mode *Session pooler*, IPv4) → `DB_*`
   - **Project URL** → `SUPABASE_URL`
   - **anon key** → `SUPABASE_ANON_KEY`
   - **service_role key** → `SUPABASE_SERVICE_ROLE_KEY`

## 2. Cloner la structure dev → prod, en une passe

Plutôt que de rejouer les 8 scripts SQL + `php artisan migrate` à la main, on
**dumpe la structure complète du schéma `public` depuis le dev** (toutes les
tables, **vides**) + les seules données de référence. Le dev a déjà tout le
schéma appliqué (scripts SQL + migrations), donc la prod en devient une copie
structurelle exacte.

```bash
# Depuis backend/ — génère le dump bootstrap depuis le dev (tables tondo_)
SRC_DB_URL="postgres://…DEV…" ./database/supabase/extract_ref_data.sh
# → tondo_prod_bootstrap_YYYYMMDD_HHMM.sql  (structure vide + config, préfixe tondo_)

# Charger sur la prod (base FRAÎCHE uniquement) le dump PROD déjà préfixé tonji_
psql "postgres://…PROD…" -f tonji_prod_bootstrap.sql
```

> **Préfixe prod = `tonji_`.** Le fichier **`tonji_prod_bootstrap.sql`** (déjà
> présent dans `backend/`) est la version prod : le dump dev (`tondo_`) y a été
> renommé `tondo_ → tonji_` dans toute la structure + la ligne `projects`
> (slug/nom/prefixe → `tonji`), en **protégeant les noms de migrations** (qui
> doivent rester `tondo_` pour matcher les fichiers sur disque). C'est ce fichier
> qu'on charge en prod, PAS le `tondo_…`.

Le dump embarque :
- **la structure de TOUTES les tables du schéma `public`** (créées vides,
  préfixe `tonji_`) — y compris fonctions, triggers, RLS, vue `tonji_transactions_unified` ;
- **les données** de `migrations` (pour que Laravel voie le schéma « à jour »),
  `projects` (slug `tonji`), `tonji_project_config`, `tonji_admins`.

> On ne dumpe que `public` : les schémas gérés par Supabase (`auth`, `storage`,
> `extensions`…) existent déjà sur le projet prod, on n'y touche pas.
>
> ❌ `006_seed_demo.sql` n'est jamais rejoué (aucune donnée démo).
> Le seed-only n'est pas copié : la prod démarre sans users/cagnottes/paiements.

## 3. Vérifier — pas de `migrate` à lancer

Comme la table `migrations` a été copiée, Laravel considère le schéma à jour :

```bash
php artisan migrate:status   # tout doit être "Ran" — NE PAS relancer migrate
```

Si un jour tu ajoutes une **nouvelle** migration, `php artisan migrate --force`
n'appliquera qu'elle (les anciennes sont déjà marquées comme faites).

## 4. `.env` prod

Repartir de `.env` puis modifier :

| Clé | Valeur prod |
|-----|-------------|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | **régénérer** : `php artisan key:generate` |
| `APP_URL` | domaine API prod (https) |
| `DB_HOST` / `DB_USERNAME` / `DB_PASSWORD` | pooler du projet **prod** |
| `SUPABASE_URL` / `SUPABASE_ANON_KEY` / `SUPABASE_SERVICE_ROLE_KEY` | clés **prod** |
| `OTP_DRIVER` | `paynala` (déjà — SMS réel Wirepick) |
| `WIREPICK_*` | **credentials Wirepick de prod** (pas la sandbox) |
| `MOBILE_TEST_OTP` | conservé (backdoor du seul numéro review Apple) |
| `DB_TABLE_PREFIX` | **`tonji_`** (dev = `tondo_`) — préfixe des tables métier |
| `PROJECT_SLUG` | **`tonji`** (dev = `tondo`) — slug dans la registry `projects` |

> ⚠️ Les deux dernières lignes sont **obligatoires** en prod : le code résout les
> noms de tables (`tonji_cagnottes`…) et le `project_id` via ces variables. Sans
> elles, l'API prod chercherait des tables `tondo_*` qui n'existent pas.

## 5. Vérifications post-bascule

- [ ] `php artisan migrate:status` → tout « Ran »
- [ ] `tonji_project_config` : 1 ligne active (frais/opérateur/tranches/logo)
- [ ] `tonji_admins` : comptes dashboard présents
- [ ] `projects` : la ligne a bien `slug = 'tonji'` (sinon `Project::tondoId()` échoue)
- [ ] Aucune donnée de test (`select count(*) from public.users;` = 0)
- [ ] Test OTP réel sur un vrai numéro + numéro de review Apple
- [ ] Health check API + RLS (un token d'un projet ne lit pas un autre projet)

## 6. Points de vigilance

- **Wirepick** : basculer des credentials **sandbox → prod** (sinon les SMS OTP
  ne partent pas en vrai). Voir `WIREPICK_CLIENT_ID` / `WIREPICK_PASSWORD`.
- **App mobile** : `api_config.dart` doit pointer vers l'URL API de prod.
- **Backups** : activer les backups quotidiens sur le projet Supabase prod.
- La FK `auth.users` restant absente, c'est **le backend** qui garantit l'unicité
  et la validité des numéros (KYC opérateur + OTP) — aucun filet Supabase Auth.

— VS Code (2026-07-07)
