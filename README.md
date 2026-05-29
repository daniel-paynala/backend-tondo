# Tondo — Backend Laravel

API REST du produit **Tondo** (tontines & cagnottes mobiles) développé par **Paynala**.

---

## Stack

| Composant | Technologie |
|---|---|
| Framework | Laravel 13 (PHP 8.3+) |
| Base de données | Supabase (PostgreSQL 15) |
| Auth mobile | Sanctum guard `mobile` + OTP SMS (Twilio) |
| Auth dashboard | Sanctum guard `admin` |
| Paiements | Paynala API (agrégateur Airtel Money Gabon) |
| Notifications push | OneSignal |
| Serveur prod | AWS (FrankenPHP / Octane) |

---

## Installation locale

```bash
composer install
cp .env.example .env
php artisan key:generate

# Configurer .env (DB, Paynala, Twilio, OneSignal…)
php artisan migrate
php artisan db:seed   # optionnel — données de démo
php artisan serve
```

---

## Structure des APIs

| Préfixe | Cible | Auth |
|---|---|---|
| `GET /api/health` | Monitoring | Public |
| `/api/admin/*` | Dashboard Next.js | Sanctum Bearer (tondo_admins) |
| `/api/mobile/*` | App Flutter | Sanctum Bearer (tondo_users) |

Voir [docs/README.md](docs/README.md) pour la documentation complète des endpoints.

---

## Tâches planifiées (cron)

| Tâche | Schedule | Description |
|---|---|---|
| `tontines:traiter-retraits` | Quotidien 20h (Libreville) | Vire la mise au bénéficiaire du cycle courant |

**Documentation complète → [docs/cron-retraits-tontines.md](docs/cron-retraits-tontines.md)**

### Activation sur le serveur AWS

Ajouter **une seule entrée** au crontab du serveur :

```bash
crontab -e
```

```
* * * * * php /var/www/html/artisan schedule:run >> /dev/null 2>&1
```

### Tester sans virement réel

```bash
php artisan tontines:traiter-retraits --dry-run
```

---

## Migrations

```bash
# Appliquer toutes les migrations
php artisan migrate

# Après chaque déploiement
git pull
php artisan migrate
php artisan cache:clear
php artisan config:clear
```

Le schéma initial (tables Supabase) est dans [`database/supabase/`](database/supabase/).

---

## Variables d'environnement clés

| Variable | Description |
|---|---|
| `DB_*` | Connexion Supabase PostgreSQL |
| `SUPABASE_URL` | URL du projet Supabase |
| `SUPABASE_SERVICE_ROLE_KEY` | Clé service-role (lectures admin) |
| `PAYNALA_BASE_URL` | URL API Paynala |
| `PAYNALA_CLIENT_ID` | Client ID OAuth Paynala |
| `PAYNALA_CLIENT_SECRET` | Secret OAuth Paynala |
| `PAYNALA_OPERATOR_KEY` | Clé opérateur Airtel |
| `TWILIO_SID` / `TWILIO_AUTH_TOKEN` | SMS OTP |
| `ONESIGNAL_APP_ID` / `ONESIGNAL_API_KEY` | Notifications push |
| `MAIL_MAILER` | `log` en dev, `smtp` en prod |
| `MAIL_FROM_ADDRESS` | Expéditeur des alertes email |
