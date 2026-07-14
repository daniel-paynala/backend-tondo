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

echo "→ Nettoyage des anciennes règles du port 80…"
# Supprime toute règle existante sur 80 (y compris un éventuel « allow 80 » ouvert
# à tous), pour repartir d'une base propre et rester idempotent.
while ufw status numbered | grep -qE '^\[[ 0-9]+\].*\b80\b'; do
  n=$(ufw status numbered | grep -E '^\[[ 0-9]+\].*\b80\b' | head -1 | sed -E 's/^\[[ ]*([0-9]+)\].*/\1/')
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

# Tout le reste du trafic entrant est refusé par défaut ; le sortant reste libre
# (le backend appelle Airtel, Supabase, Twilio, Wirepick…).
ufw default deny incoming
ufw default allow outgoing

echo "→ Activation du pare-feu…"
ufw --force enable

echo
echo "✅ Terminé. Règles actives :"
ufw status verbose | head -30

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
