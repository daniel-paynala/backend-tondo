#!/usr/bin/env bash
#
# Génère un dump « bootstrap prod » depuis la base source (dev/test) :
#
#   1. La STRUCTURE COMPLÈTE du schéma public — TOUTES les tables, mais VIDES.
#      (Évite de rejouer manuellement les scripts SQL + `php artisan migrate`.)
#   2. Les DONNÉES uniquement pour les tables de référence / tracking :
#        - public.migrations           (pour que Laravel sache que le schéma est à jour)
#        - public.projects             (registry — la ligne Tondo)
#        - public.tondo_project_config (frais, opérateur, tranches, indicatifs, logo)
#        - public.tondo_admins         (comptes du dashboard admin)
#
# Résultat : la prod obtient toutes les tables (vides) + la config, en une passe.
# AUCUNE donnée de test (users, cagnottes, paiements, transactions) n'est copiée.
#
# Usage :
#   SRC_DB_URL="postgres://user:pass@host:5432/postgres" ./extract_ref_data.sh
#
# Charger ensuite sur la prod (base fraîche uniquement) :
#   psql "postgres://…PROD…" -f <fichier généré>

set -euo pipefail

SRC_DB_URL="${SRC_DB_URL:?Définir SRC_DB_URL=postgres://... (base dev/test source)}"
OUT="${OUT:-tondo_prod_bootstrap_$(date +%Y%m%d_%H%M).sql}"

# Tables dont on copie AUSSI les données (le reste est créé vide).
# Ordre géré automatiquement par pg_dump (dépendances FK).
DATA_TABLES=(
  public.migrations
  public.projects
  public.tondo_project_config
  public.tondo_admins
)

echo "→ 1/2 Structure complète du schéma public (toutes les tables, vides)…"
{
  echo "-- ================================================================"
  echo "-- Bootstrap prod Tondo"
  echo "-- Structure complète du schéma public (tables VIDES) + données de"
  echo "-- référence (migrations, projects, tondo_project_config, tondo_admins)."
  echo "-- Généré depuis la base source. Aucune donnée de test copiée."
  echo "-- ================================================================"
  echo ""

  # Schéma seul : tables, séquences, fonctions, vues, triggers, RLS — pas de data.
  # Restreint à `public` : on ne touche pas aux schémas gérés par Supabase
  # (auth, storage, extensions…) déjà présents sur le projet prod.
  pg_dump "$SRC_DB_URL" \
    --schema=public \
    --schema-only \
    --no-owner \
    --no-privileges

  echo ""
  echo "-- ===== Données de référence (config + tracking migrations) ====="

  data_args=()
  for t in "${DATA_TABLES[@]}"; do data_args+=(--table="$t"); done

  pg_dump "$SRC_DB_URL" \
    --data-only \
    --column-inserts \
    --no-owner \
    --no-privileges \
    "${data_args[@]}"
} > "$OUT"

echo "→ 2/2 Données de référence ajoutées."
echo ""
echo "✓ Terminé : $OUT"
echo "  • Structure : toutes les tables du schéma public (vides)"
echo "  • Données   : ${DATA_TABLES[*]}"
echo ""
echo "Charger sur la prod (base fraîche) :"
echo "  psql \"\$DEST_DB_URL\" -f $OUT"
