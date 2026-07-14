#!/usr/bin/env bash
#
# Verrouille l'origine : seul Cloudflare peut joindre le port 80.
#
# POURQUOI
#   L'API est servie via api.tonji.ga : Cloudflare termine le TLS et parle à
#   l'origine en HTTP clair. Laravel fait donc confiance à l'en-tête
#   X-Forwarded-Proto (TrustProxies, cf. bootstrap/app.php).
#   Tant que l'IP publique répond directement à tout le monde, n'importe qui
#   peut la joindre en contournant Cloudflare et envoyer un faux
#   X-Forwarded-Proto — donc usurper le schéma, et contourner au passage le
#   WAF, le rate-limiting et le cache de Cloudflare.
#   Ce script ne laisse entrer sur le port 80 que les plages Cloudflare.
#
# SÛRETÉ
#   - Le port 22 (SSH) est autorisé EN PREMIER et n'est jamais restreint :
#     impossible de se verrouiller dehors.
#   - Les plages Cloudflare sont retéléchargées depuis la source officielle
#     à chaque exécution (elles évoluent). Relancer ce script périodiquement.
#   - Idempotent : on repart d'un jeu de règles propre pour le port 80.
#
# USAGE
#   sudo bash lock-origin-cloudflare.sh
#
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
  echo "❌ À lancer en root : sudo bash $0" >&2
  exit 1
fi

echo "→ Récupération des plages Cloudflare (source officielle)…"
V4=$(curl -fsS https://www.cloudflare.com/ips-v4)
V6=$(curl -fsS https://www.cloudflare.com/ips-v6)

# Garde-fou : si la source renvoie une réponse vide/anormale, on n'applique rien.
# Sans ça, un échec réseau produirait une règle « personne ne passe ».
if [[ $(grep -c . <<<"$V4") -lt 10 ]]; then
  echo "❌ Liste IPv4 Cloudflare suspecte (moins de 10 plages). Abandon." >&2
  exit 1
fi

echo "→ SSH d'abord : on s'assure de ne jamais se couper l'accès."
ufw allow 22/tcp comment 'SSH — ne jamais restreindre'

# ⚠️ ORDRE CRITIQUE : activer AVANT de nettoyer.
# Sur un pare-feu inactif, `ufw status` n'affiche AUCUNE règle — même celles
# déjà enregistrées. Nettoyer à ce moment-là ne trouve donc rien, et un
# `ufw enable` ultérieur réactive les anciennes règles ouvertes (typiquement le
# profil « Nginx Full », qui autorise 80,443 depuis Anywhere et rend toute la
# restriction Cloudflare inopérante).
echo "→ Activation du pare-feu (avant nettoyage, sinon les règles restent invisibles)…"
ufw default deny incoming
ufw default allow outgoing
ufw --force enable

echo "→ Suppression des profils applicatifs qui ouvrent 80/443 à tous…"
# Profils Nginx/Apache posés à l'installation : ils autorisent Anywhere et
# prennent le pas sur nos règles Cloudflare. `|| true` : absents = non bloquant.
for profil in 'Nginx Full' 'Nginx HTTP' 'Nginx HTTPS' 'Apache Full' 'Apache'; do
  ufw delete allow "$profil" 2>/dev/null || true
done

echo "→ Nettoyage des anciennes règles ouvertes sur le port 80…"
# Supprime toute règle sur 80 encore ouverte à « Anywhere ». On ne touche pas
# aux règles restreintes à une source (les nôtres n'existent pas encore ici).
while ufw status numbered | grep -qE '^\[[ 0-9]+\].*\b80\b.*Anywhere'; do
  n=$(ufw status numbered | grep -E '^\[[ 0-9]+\].*\b80\b.*Anywhere' | head -1 | sed -E 's/^\[[ ]*([0-9]+)\].*/\1/')
  ufw --force delete "$n"
done

echo "→ Autorisation du port 80 pour Cloudflare uniquement…"
while read -r cidr; do
  [[ -z "$cidr" ]] && continue
  ufw allow from "$cidr" to any port 80 proto tcp comment 'Cloudflare'
done <<<"$V4"

while read -r cidr; do
  [[ -z "$cidr" ]] && continue
  ufw allow from "$cidr" to any port 80 proto tcp comment 'Cloudflare v6'
done <<<"$V6"

ufw reload

echo
echo "✅ Terminé. Règles actives :"
ufw status verbose | head -40

# Garde-fou final : si une règle laisse encore le port 80 ouvert à tous, la
# restriction Cloudflare ne sert à rien — on le dit haut et fort.
if ufw status | grep -qE '\b80\b.*Anywhere'; then
  echo
  echo "⚠️  ATTENTION : le port 80 est ENCORE ouvert à « Anywhere ». L'origine"
  echo "    reste joignable en direct et le verrouillage est inopérant."
  echo "    Repérer la règle ci-dessus et la supprimer :  sudo ufw delete allow 'Nginx Full'"
  exit 1
fi
echo "✔ Aucune règle n'ouvre plus le port 80 à « Anywhere »."

cat <<'EOF'

────────────────────────────────────────────────────────────
À VÉRIFIER MAINTENANT, depuis une machine EXTÉRIEURE :

  # Doit CONTINUER à fonctionner (passe par Cloudflare) :
  curl -s -o /dev/null -w '%{http_code}\n' https://api.tonji.ga/up
      → 200

  # Doit MAINTENANT échouer / timeout (accès direct à l'origine) :
  curl --max-time 8 http://51.44.254.213/up
      → timeout

Si le premier casse, relancer :  sudo ufw disable
────────────────────────────────────────────────────────────
EOF
