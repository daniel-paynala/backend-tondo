# Supabase — schéma Tondo

SQL versionné Git, à appliquer **dans l'ordre** sur l'instance Supabase
partagée (test/dev) de Paynala.

## Modèle de données

Architecture multi-projets sur une seule base (économique pour la phase
test). Daniel hébergera plusieurs projets sur la même instance Supabase.

```
┌─ public ──────────────────────────────────────────────────┐
│                                                             │
│  projects  ──────┐                                          │
│   (registry)     │                                          │
│                  │                                          │
│  users  ─────────┤  project_id  (chaque user appartient   │
│   (FK auth.users)│                à UN projet)              │
│                  │                                          │
│  tondo_cagnottes ┘                                          │
│  tondo_participants                                         │
│  tondo_paiements                                            │
│                                                             │
│  (autre_xxx)     ← futur projet                            │
│  (autre_yyy)                                                │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

- **Tables partagées** : `projects` (registry des produits) et `users`
  (profil étendu au-dessus de `auth.users` Supabase, avec `project_id`).
- **Tables métier** : toutes préfixées par le `prefixe` du projet
  (Tondo → `tondo_*`). Chaque ligne dénormalise `project_id` pour des
  policies RLS rapides (O(1)).
- **Row Level Security** activé partout : un user authentifié ne peut
  jamais lire ou écrire des rows d'un autre projet, même si l'application
  tente une requête libre.

## Ordre d'exécution

Coller chaque fichier dans **Supabase Dashboard → SQL Editor → New query**
puis « Run ». Les scripts sont **idempotents** (peuvent être ré-exécutés
sans casser l'état).

| # | Fichier                       | Rôle                                                                              |
|---|-------------------------------|-----------------------------------------------------------------------------------|
| 1 | `001_fondation.sql`           | `projects` + `users` + auth trigger + helper `current_project_id()` + RLS infra.  |
| 2 | `002_tondo.sql`               | Tables métier Tondo (`tondo_cagnottes`, `tondo_participants`, `tondo_paiements`). |
| 3 | `003_tondo_transactions.sql`  | Tables transactionnelles (`tondo_payin`, `tondo_payout`, `tondo_payout_paynala`) + `tondo_retry` pour la réconciliation financière. |

## Auth — Phone OTP

L'auth est gérée par Supabase Auth (phone + SMS OTP). Côté client (Flutter
ou Next.js admin) :

```dart
// Flutter
await Supabase.instance.client.auth.signInWithOtp(
  phone: '+241XXXXXXXX',
  data: {
    'project_slug': 'tondo',
    'nom': 'Doviakon',
    'prenom': 'Daniel',
    'date_naissance': '1992-07-14',
    'type_client': 'particulier',
  },
);
```

Le trigger `on_auth_user_created` (script 001) lit ce metadata, résout le
`project_id` via le slug, et crée la row `public.users` correspondante.

## Quand on ajoute un nouveau projet

1. INSERT dans `projects` avec `(slug, nom, prefixe)`.
2. Créer les tables métier préfixées (`monprojet_*`).
3. Activer RLS + policies (copier le pattern de `002_tondo.sql`).
4. Les apps de ce nouveau projet passent leur `project_slug` au signUp
   pour que le trigger les rattache.

## Migration vers prod

Quand un projet sort de test :

1. `pg_dump` filtré sur les tables `monprojet_*` + extraction de la row
   correspondante dans `projects` et des rows de `users` matchant le
   `project_id`.
2. Restauration dans une instance Supabase dédiée au projet.
3. Suppression des tables `monprojet_*` de l'instance test partagée.

## Variables d'environnement attendues côté Laravel

Une fois l'instance Supabase créée et les SQL exécutés, configurer
`backend/.env` :

```bash
DB_CONNECTION=pgsql
DB_HOST=<host>.supabase.co
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres
DB_PASSWORD=<password>
DB_SCHEMA=public

# Supabase API (pour appels REST/Realtime depuis Laravel si besoin)
SUPABASE_URL=https://<id>.supabase.co
SUPABASE_ANON_KEY=<anon-public-key>
SUPABASE_SERVICE_ROLE_KEY=<service-role-key>  # NE PAS exposer côté client
```

Côté `admin/.env.local` (Next.js) :

```bash
NEXT_PUBLIC_SUPABASE_URL=https://<id>.supabase.co
NEXT_PUBLIC_SUPABASE_ANON_KEY=<anon-public-key>
```

Côté Flutter (`mobile/`) — à brancher via `flutter_dotenv` ou
`String.fromEnvironment` dans une session ultérieure.
