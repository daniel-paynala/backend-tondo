# Tondo — Documentation API

## Vue d'ensemble

Le backend Tondo (Laravel 13) expose **deux familles d'API** strictement séparées :

| Préfixe | Cible | Auth | Statut |
|---|---|---|---|
| `/api/admin/*` | Dashboard Next.js | **Sanctum** (Bearer token sur `tondo_admins`) | ✅ Implémenté |
| `/api/mobile/*` | App Flutter | **Supabase JWT** (phone OTP) — middleware à brancher | 🚧 Stubs 501 |
| `/api/health` | Monitoring | Public | ✅ |

## Collection Postman

Une collection globale `Tondo` est versionnée dans
[postman/tondo.postman_collection.json](postman/tondo.postman_collection.json).

### Import

```
Postman → Import → File → backend/docs/postman/tondo.postman_collection.json
```

### Structure

```
Tondo
├── Health
│   └── GET /api/health
├── API Dashboard
│   ├── Auth          (login, me, logout)
│   ├── Utilisateurs  (list, show)
│   ├── Administrateurs (CRUD)
│   ├── Tontines & cotisations (list, show, cloturer)
│   ├── Transactions  (unified, payin, payout, payout-paynala)
│   ├── Signalements  (list, show, update statut)
│   └── Logs          (list)
└── API Mobile        (stubs 501)
    ├── Auth          (check-phone, me)
    ├── Cagnottes     (list, create, detail)
    ├── Cotisations   (cotiser)
    └── Profil        (get, update)
```

### Variables de collection

| Variable | Rôle | Valeur par défaut |
|---|---|---|
| `base_url` | URL du backend Laravel | `http://127.0.0.1:8000` |
| `admin_token` | Token Sanctum admin (auto-rempli après login) | vide |

Le token est **automatiquement extrait et stocké** par le test script du
`POST /api/admin/login`. Les autres endpoints du folder « API Dashboard »
l'utilisent via `Authorization: Bearer {{admin_token}}` (auth héritée au
niveau du folder).

### Workflow

1. **Lance le backend** : `cd backend && php artisan serve` (port 8000).
2. **Importe la collection** dans Postman.
3. **Exécute** `API Dashboard → Auth → POST /api/admin/login`.
   → Le token est extrait et stocké automatiquement dans `admin_token`.
4. Tous les autres endpoints dashboard fonctionnent immédiatement.

## Conventions générales

### Pagination

Toutes les list endpoints utilisent la pagination Laravel standard :

```json
{
  "current_page": 1,
  "data": [...],
  "first_page_url": "...",
  "from": 1,
  "last_page": 5,
  "per_page": 25,
  "to": 25,
  "total": 117
}
```

Params : `?page=N` et `?per_page=N` (max 100, ou 200 pour les logs).

### Filtres

Chaque list endpoint expose des filtres par query string :
- `?q=...` (recherche texte sur les champs pertinents)
- `?statut=...`, `?type=...`, etc. selon l'entité

### Erreurs

- **401 Unauthenticated** : token invalide ou absent.
- **403 Forbidden** : action réservée (ex : super_admin uniquement).
- **422 Unprocessable Entity** : payload invalide. Body :
  ```json
  { "message": "...", "errors": { "field": ["..."] } }
  ```
- **404 Not Found** : ressource inexistante ou hors du projet.
- **501 Not Implemented** : endpoint mobile pas encore branché.

## Scoping multi-projets

Toutes les tables métier (`tondo_*`) sont scopées par `project_id` côté DB
via RLS. Les controllers Laravel filtrent **toujours** par
`$request->user()->project_id` (le `project_id` de l'admin connecté). Un
admin ne peut jamais voir ou modifier les données d'un autre projet, même
s'il forge la requête.

## Schéma DB

Les SQL Supabase versionnés sont dans
[../database/supabase/](../database/supabase/) :

| Fichier | Tables |
|---|---|
| `001_fondation.sql` | `projects`, `users` (extends auth.users), trigger auth, RLS infra |
| `002_tondo.sql` | `tondo_cagnottes`, `tondo_participants`, `tondo_paiements` |
| `003_tondo_transactions.sql` | `tondo_payin`, `tondo_payout`, `tondo_payout_paynala`, `tondo_retry` |
| `004_tondo_admins.sql` | `tondo_admins` (auth dashboard) |
| `005_tondo_signalements_logs.sql` | `tondo_signalements`, `tondo_logs`, vue `tondo_transactions_unified` |

## TODO mobile

Les endpoints `/api/mobile/*` sont en stub. Pour les implémenter :

1. **Middleware Supabase JWT** — décoder le JWT issu de Supabase Auth,
   résoudre l'`auth.uid()` en `public.users` row, l'attacher à la requête.
2. **Controllers** `Api\Mobile\AuthController`, `CagnottesController`,
   `CotisationsController`, `ProfilController`.
3. **Service** d'intégration agrégateur de paiement (Q6 ouverte) pour
   déclencher les payin/payout réels.

À faire dans une session dédiée après stabilisation du dashboard.
