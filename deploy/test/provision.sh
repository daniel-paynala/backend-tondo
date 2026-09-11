#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
#  Provisionnement de l'instance de TEST (Amazon Linux 2023, 13.39.79.94).
#
#  À lancer UNE FOIS, en ec2-user. Le script est idempotent : le relancer ne
#  casse rien et ne duplique rien.
#
#      scp -i ~/.ssh/tondo.pem provision.sh ec2-user@13.39.79.94:~
#      ssh -i ~/.ssh/tondo.pem ec2-user@13.39.79.94 'bash ~/provision.sh'
#
#  Ce qu'il NE fait pas, volontairement :
#   – aucun cron (le planificateur contient des commandes qui décaissent de
#     l'argent et envoient des SMS à de vrais numéros ; on les activera une
#     par une, en connaissance de cause) ;
#   – aucune migration de base (la base de test se met à jour en rejouant les
#     fichiers SQL dans Supabase, jamais par `artisan migrate`) ;
#   – aucun déploiement de code : c'est le rôle de deploy.sh.
# ═══════════════════════════════════════════════════════════════════════════
set -euo pipefail

echo "──────────────────────────────────────────────────────────"
echo " Provisionnement — $(. /etc/os-release && echo "$PRETTY_NAME")"
echo "──────────────────────────────────────────────────────────"

# ── 1. Swap ────────────────────────────────────────────────────────────────
# 913 Mo de RAM. `next build` en réclame couramment 1,5 Go et se ferait tuer
# par l'OOM killer au milieu de la compilation, avec un message peu parlant.
# Deux giga de swap coûtent 2 Go de disque sur les 6,4 disponibles.
if ! swapon --show | grep -q '/swapfile'; then
  echo "▸ Création du swap (2 Go)…"
  sudo dd if=/dev/zero of=/swapfile bs=1M count=2048 status=none
  sudo chmod 600 /swapfile
  sudo mkswap /swapfile >/dev/null
  sudo swapon /swapfile
  grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab >/dev/null
  # Swap de secours, pas de confort : on ne veut y basculer qu'à la contrainte.
  sudo sysctl -q vm.swappiness=10
  echo 'vm.swappiness=10' | sudo tee /etc/sysctl.d/99-swappiness.conf >/dev/null
else
  echo "▸ Swap déjà en place."
fi

# ── 2. Paquets de base ─────────────────────────────────────────────────────
echo "▸ Installation git, nginx, outils…"
sudo dnf install -y -q git nginx tar unzip

# ── 3. PHP ─────────────────────────────────────────────────────────────────
# composer.json exige ^8.3. On prend la plus récente disponible qui convienne.
PHPV=""
for v in 8.4 8.3; do
  if dnf list available "php${v}" >/dev/null 2>&1 || dnf list installed "php${v}" >/dev/null 2>&1; then
    PHPV="$v"; break
  fi
done
if [ -z "$PHPV" ]; then
  echo "✖ Aucun paquet php8.3 ou php8.4 dans les dépôts. Arrêt." >&2
  exit 1
fi
echo "▸ PHP ${PHPV}…"

# Installés un par un : une extension absente d'AL2023 ne doit pas faire
# échouer tout le lot, elle doit se signaler nommément.
for p in "php${PHPV}" "php${PHPV}-fpm" "php${PHPV}-cli" "php${PHPV}-pgsql" \
         "php${PHPV}-mbstring" "php${PHPV}-xml" "php${PHPV}-opcache" \
         "php${PHPV}-bcmath" "php${PHPV}-gd" "php${PHPV}-intl" "php${PHPV}-sodium"; do
  sudo dnf install -y -q "$p" 2>/dev/null || echo "  ⚠ paquet indisponible, ignoré : $p"
done
php -v | head -1

# ── 4. Pool php-fpm dédié ──────────────────────────────────────────────────
# Le pool `www` par défaut écoute en TCP et tourne sous apache. On le met de
# côté et on en pose un à nous, sur socket Unix, aligné sur nginx.
if [ -f /etc/php-fpm.d/www.conf ]; then
  sudo mv /etc/php-fpm.d/www.conf /etc/php-fpm.d/www.conf.disabled
  echo "▸ Pool php-fpm par défaut désactivé."
fi
sudo tee /etc/php-fpm.d/tonji.conf >/dev/null <<'FPM'
; Pool applicatif Tonji — environnement de test.
[tonji]
user  = ec2-user
group = nginx

listen       = /run/php-fpm/tonji.sock
listen.owner = nginx
listen.group = nginx
listen.mode  = 0660

; `ondemand` plutôt que `dynamic` : sur 913 Mo, garder des processus au chaud
; pour un environnement sollicité par intermittence coûte plus qu'il ne sert.
pm                   = ondemand
pm.max_children      = 6
pm.process_idle_timeout = 30s
pm.max_requests      = 500

php_admin_value[error_log] = /var/log/php-fpm/tonji-error.log
php_admin_flag[log_errors] = on

; Les pièces jointes des demandes de plafond association ; aligné sur le
; client_max_body_size de nginx.
php_admin_value[upload_max_filesize] = 12M
php_admin_value[post_max_size]       = 12M
php_admin_value[memory_limit]        = 256M
FPM

# ── 5. Node ────────────────────────────────────────────────────────────────
# Next 16 exige Node ≥ 20.9.
if ! command -v node >/dev/null 2>&1; then
  NODEPKG=""
  for v in nodejs22 nodejs20; do
    if dnf list available "$v" >/dev/null 2>&1; then NODEPKG="$v"; break; fi
  done
  if [ -n "$NODEPKG" ]; then
    echo "▸ Node via ${NODEPKG}…"
    sudo dnf install -y -q "$NODEPKG" "${NODEPKG}-npm" 2>/dev/null || sudo dnf install -y -q "$NODEPKG"
  else
    echo "▸ Node via NodeSource (aucun paquet AL2023 convenable)…"
    curl -fsSL https://rpm.nodesource.com/setup_22.x | sudo bash -
    sudo dnf install -y -q nodejs
  fi
fi
echo "  node $(node -v) / npm $(npm -v)"

# ── 6. Composer ────────────────────────────────────────────────────────────
if ! command -v composer >/dev/null 2>&1; then
  echo "▸ Composer…"
  EXPECTED=$(curl -fsSL https://composer.github.io/installer.sig)
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  ACTUAL=$(php -r "echo hash_file('sha384','/tmp/composer-setup.php');")
  # L'installeur de Composer est un script exécuté en tant que root : on
  # vérifie son empreinte avant de le lancer, comme le recommande son auteur.
  [ "$EXPECTED" = "$ACTUAL" ] || { echo "✖ Empreinte de l'installeur Composer invalide." >&2; exit 1; }
  sudo php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
composer --version

# ── 7. Arborescence ────────────────────────────────────────────────────────
echo "▸ /var/www/{Backend,Admin}…"
sudo mkdir -p /var/www/Backend /var/www/Admin
sudo chown -R ec2-user:nginx /var/www
sudo chmod 750 /var/www
# nginx doit traverser /var/www pour lire public/ du backend.
sudo chmod 755 /var/www

# ── 8. nginx ───────────────────────────────────────────────────────────────
# Le paquet AL2023 livre un bloc `server` d'exemple qui écoute lui aussi sur le
# 80 avec server_name _, et sert la page d'accueil nginx. On le retire : deux
# blocs concurrents sur le même port rendent le routage illisible.
#
# Il est repéré par sa racine /usr/share/nginx/html puis délimité en comptant
# les accolades. Le mot-clé `default_server` ne peut pas servir de repère : ce
# paquet ne l'emploie pas.
if grep -qE '^[[:space:]]*root[[:space:]]+/usr/share/nginx/html;' /etc/nginx/nginx.conf; then
  sudo cp -n /etc/nginx/nginx.conf /etc/nginx/nginx.conf.orig
  sudo python3 /dev/stdin <<'PYBLOC'
chemin = '/etc/nginx/nginx.conf'
lignes = open(chemin).read().splitlines(keepends=True)

debut = candidat = None
for i, l in enumerate(lignes):
    nu = l.strip()
    if nu.startswith('#'):
        continue
    if nu.startswith('server {'):
        candidat = i
    if 'root' in nu and '/usr/share/nginx/html' in nu:
        debut = candidat
        break

if debut is not None:
    profondeur, fin = 0, None
    for i in range(debut, len(lignes)):
        sans_commentaire = lignes[i].split('#', 1)[0]
        profondeur += sans_commentaire.count('{') - sans_commentaire.count('}')
        if profondeur == 0:
            fin = i
            break
    del lignes[debut:fin + 1]
    open(chemin, 'w').write(''.join(lignes))
    print('  bloc server par defaut retire (lignes %d a %d)' % (debut + 1, fin + 1))
PYBLOC
fi

# ── 9. Vhosts et unité systemd ─────────────────────────────────────────────
# Pris dans le dossier qui porte ce script, pour que provision.sh reste
# utilisable quel que soit l'endroit où on l'a déposé.
ICI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
echo "▸ Pose des vhosts et de l'unité systemd…"
sudo cp "$ICI/nginx-backend.conf"  /etc/nginx/conf.d/backend-test.conf
sudo cp "$ICI/nginx-admin.conf"    /etc/nginx/conf.d/admin-test.conf
sudo cp "$ICI/tonji-admin.service" /etc/systemd/system/tonji-admin.service
sudo systemctl daemon-reload
sudo nginx -t

sudo systemctl enable --now php-fpm nginx >/dev/null 2>&1 || true

# ── 10. SELinux ─────────────────────────────────────────────────────────────
if command -v getenforce >/dev/null 2>&1 && [ "$(getenforce)" = "Enforcing" ]; then
  echo "▸ SELinux actif : autorisation des connexions sortantes nginx…"
  sudo setsebool -P httpd_can_network_connect 1
fi

echo
echo "──────────────────────────────────────────────────────────"
echo " Provisionnement terminé."
echo
echo " Reste à faire, dans l'ordre :"
echo "  1. Ouvrir les ports 80 et 8080 dans le security group AWS."
echo "  2. Déposer les fichiers d'environnement (voir deploy.sh)."
echo "  3. Lancer deploy.sh."
echo "──────────────────────────────────────────────────────────"
