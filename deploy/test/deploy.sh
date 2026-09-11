#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
#  Déploiement par git sur l'instance de TEST.
#
#      bash ~/deploy.sh            # les deux
#      bash ~/deploy.sh backend
#      bash ~/deploy.sh admin
#
#  Le jeton GitHub n'est PAS dans ce fichier : ce script est versionné, l'y
#  écrire le publierait sur le dépôt. Il est lu depuis ~/.tonji-deploy.env,
#  posé à la main sur l'instance :
#
#      echo 'GITHUB_TOKEN=ghp_…' > ~/.tonji-deploy.env && chmod 600 ~/.tonji-deploy.env
#
#  Les fichiers d'environnement (/var/www/Backend/.env et
#  /var/www/Admin/.env.local) sont posés à la main eux aussi, une fois pour
#  toutes : ils portent des secrets et ne doivent pas transiter par git.
# ═══════════════════════════════════════════════════════════════════════════
set -euo pipefail

CIBLE="${1:-all}"

REPO_BACKEND="github.com/daniel-paynala/backend-tondo.git"
REPO_ADMIN="github.com/daniel-paynala/dashboard-tondo.git"
BRANCHE="main"

[ -f ~/.tonji-deploy.env ] || { echo "✖ ~/.tonji-deploy.env absent (GITHUB_TOKEN)." >&2; exit 1; }
# shellcheck disable=SC1090
. ~/.tonji-deploy.env
[ -n "${GITHUB_TOKEN:-}" ] || { echo "✖ GITHUB_TOKEN vide dans ~/.tonji-deploy.env." >&2; exit 1; }

# ── Récupère ou met à jour un dépôt ────────────────────────────────────────
# `reset --hard` plutôt que `pull` : on veut que l'instance soit EXACTEMENT à
# l'état de la branche distante. Une modification faite à chaud sur le serveur
# doit disparaître au déploiement suivant, pas provoquer un conflit de fusion.
#
# Le premier passage n'utilise PAS `git clone` : le dossier contient déjà le
# fichier d'environnement, déposé à la main, et clone refuse toute cible non
# vide. On initialise donc le dépôt sur place, ce qui laisse le .env intact.
synchroniser() {
  local chemin="$1" repo="$2"
  local url="https://${GITHUB_TOKEN}@${repo}"

  if [ ! -d "$chemin/.git" ]; then
    echo "▸ Initialisation du dépôt dans $chemin…"
    mkdir -p "$chemin"
    git init -q -b "$BRANCHE" "$chemin"
    git -C "$chemin" remote add origin "$url"
  else
    echo "▸ Mise à jour de $chemin…"
    git -C "$chemin" remote set-url origin "$url"
  fi

  git -C "$chemin" fetch -q --depth 20 origin "$BRANCHE"
  git -C "$chemin" reset -q --hard "origin/$BRANCHE"
  # Retire ce qui traîne hors du dépôt, en épargnant ce qui ne doit jamais
  # être régénéré par git : environnements, dépendances, fichiers écrits par
  # l'application.
  git -C "$chemin" clean -qfd \
      -e .env -e .env.local -e storage -e node_modules -e vendor -e .next

  echo "  → $(git -C "$chemin" log -1 --format='%h %s')"
}

# ═══ Backend ═══════════════════════════════════════════════════════════════
if [ "$CIBLE" = "all" ] || [ "$CIBLE" = "backend" ]; then
  echo
  echo "═══ Backend ═══"
  synchroniser /var/www/Backend "$REPO_BACKEND"

  [ -f /var/www/Backend/.env ] || {
    echo "✖ /var/www/Backend/.env absent. Partir de deploy/test/.env.test.example." >&2
    exit 1
  }

  cd /var/www/Backend
  echo "▸ composer install…"
  composer install --no-dev --optimize-autoloader --no-interaction --quiet

  # ⚠️ Aucune migration. La base de test se met à jour en rejouant les fichiers
  # de database/supabase/ dans l'éditeur SQL Supabase. `artisan migrate` ne
  # connaît pas le préfixe tondo_ et écrirait à côté.

  echo "▸ Caches applicatifs…"
  php artisan optimize:clear --quiet
  php artisan optimize --quiet
  php artisan storage:link --quiet 2>/dev/null || true

  chmod -R ug+rwX storage bootstrap/cache

  echo "▸ Rechargement php-fpm…"
  sudo systemctl reload php-fpm

  echo "  Backend à jour."
fi

# ═══ Admin ═════════════════════════════════════════════════════════════════
if [ "$CIBLE" = "all" ] || [ "$CIBLE" = "admin" ]; then
  echo
  echo "═══ Admin ═══"
  synchroniser /var/www/Admin "$REPO_ADMIN"

  [ -f /var/www/Admin/.env.local ] || {
    echo "✖ /var/www/Admin/.env.local absent." >&2
    echo "  Attention : NEXT_PUBLIC_DB_PREFIX doit valoir tondo_ AVANT la" >&2
    echo "  compilation — Next fige ces variables dans le bundle." >&2
    exit 1
  }

  # Garde-fou : une compilation faite avec le préfixe de production donnerait
  # un backoffice qui affiche les données RÉELLES en se croyant en test. Le
  # symptôme est invisible à l'œil ; la vérification coûte une ligne.
  if ! grep -qE '^\s*NEXT_PUBLIC_DB_PREFIX=tondo_' /var/www/Admin/.env.local; then
    echo "✖ NEXT_PUBLIC_DB_PREFIX n'est pas tondo_ dans .env.local. Arrêt." >&2
    exit 1
  fi

  cd /var/www/Admin
  echo "▸ npm ci…"
  npm ci --no-audit --no-fund

  echo "▸ next build…"
  # 913 Mo de RAM : on borne le tas de Node pour qu'il s'appuie sur le swap
  # plutôt que de se faire tuer par l'OOM killer en fin de compilation.
  NODE_OPTIONS="--max-old-space-size=1536" npm run build

  echo "▸ Redémarrage du service…"
  sudo systemctl restart tonji-admin

  echo "  Admin à jour."
fi

echo
echo "──────────────────────────────────────────────────────────"
echo " Backoffice : http://13.39.79.94/"
echo " API        : http://13.39.79.94:8080/"
echo "──────────────────────────────────────────────────────────"
