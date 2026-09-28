# Audit d'intégrité

## Pourquoi

Le bot WhatsApp a émis pendant des mois des références `TONDOPAYIN` vers
l'opérateur, alors que le reste du produit était passé à `TONJI`. Ce n'était
pas un bug de logique : la constante était écrite à la main dans quatre
fichiers et rien ne les comparait.

L'audit existe pour que ce type d'écart soit détecté par une machine, à
l'écriture puis en production.

## Utilisation

```bash
php artisan tonji:audit              # contrôles statiques, sans base
php artisan tonji:audit --donnees    # ajoute les contrôles en base
php artisan tonji:audit --strict     # les avertissements font échouer aussi
```

Le code de sortie vaut 1 dès la première anomalie : la commande tourne en
intégration continue (`.github/workflows/audit.yml`) et peut servir de
pré-commit.

## Ce qui est contrôlé

| Contrôle | Question posée |
|---|---|
| Préfixes | Un préfixe banni est-il encore émis ? Un préfixe non déclaré traîne-t-il dans le code ? |
| Drapeaux | `tontines_actives` porte-t-il la même valeur côté backend, mobile et web ? |
| Tâches | Le planificateur et le registre décrivent-ils les mêmes tâches ? |
| Réglages | Les réglages sans lesquels une fonction tombe en silence sont-ils renseignés ? |
| Versions | Les versions des dépôts se contredisent-elles ? |
| Schéma *(--donnees)* | Les colonnes attendues existent-elles dans la base connectée ? |
| Dérive *(--donnees)* | Quels préfixes ont **réellement** été émis ces 30 derniers jours ? |

Le dernier est le filet de sécurité des autres : il regarde les données, pas
le code, et attrape donc un préfixe construit dynamiquement ou un chemin
oublié lors d'un renommage.

## La règle

Toute constante structurante — préfixe de transaction, drapeau de
fonctionnalité, tâche planifiée, réglage critique — se déclare dans
`app/Support/Registre.php`. Le code lit le registre au lieu d'écrire la
valeur. L'audit refuse ce qui n'y figure pas.

## Niveaux

- **Anomalie** : l'audit échoue. Un préfixe banni, un drapeau désaligné, une
  tâche disparue, une colonne manquante.
- **Avertissement** : signalé sans faire échouer. Par exemple une clé de
  production absente d'un poste de développement — sur `production` et
  `staging`, le même manque devient une anomalie.
- **Ignoré** : contrôle non applicable ici, typiquement un dépôt voisin absent.
