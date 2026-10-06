# Collection Airtel Money — Tonji

Tout ce qui touche à **Airtel Money**, via l'agrégateur Paynala, dans une
collection automatisée. Deux formats, même contenu :

| Outil | Chemin |
|---|---|
| **Bruno** (dossier à ouvrir, pas d'import) | `docs/bruno/tonji-airtel/` |
| **Postman** (collection + environnement à importer) | `docs/postman/tonji-airtel.postman_collection.json` |

Bruno n'importe que les *collections* Postman, pas ses fichiers d'environnement,
et sa compatibilité `pm.*` est partielle : la version Bruno est donc écrite
nativement avec `bru` / `req` / `res`, pas traduite.

> ⚠️ **Il n'existe aucun bac à sable Paynala.** `testapi.paynala.com` — la valeur
> par défaut dans le code — ne résout pas ; seul `api.paynala.com` existe. Chaque
> requête de cette collection touche de l'argent réel sur de vrais comptes
> Airtel Money. Les garde-fous décrits plus bas sont là pour ça.

## Ce qu'elle couvre

| Dossier | Endpoint | Rôle |
|---|---|---|
| 1 · KYC | `POST /kyc` | Le numéro a-t-il un compte Airtel Money actif ? Rend nom, prénom et `grade`. |
| 2 · Push | `POST /create_payment_v2` | Pousse la demande de paiement sur le téléphone du cotisant. |
| 2 · Push | `GET /payment_status_v2` | `PENDING` / `SUCCESS` / `FAILED`. En une lecture, ou en attente active. |
| 3 · Décaissement | `POST /disburse` | Sortie d'argent, B2C ou B2B selon le grade, avec clé d'idempotence. |
| 0 · Jeton | `POST /oauth_token_v2` | Jeton `client_credentials` — déjà automatique, exposé pour inspection. |

Ce sont exactement les cinq appels que fait
[`app/Services/PaynalaPaymentService.php`](../app/Services/PaynalaPaymentService.php).
La collection n'ajoute aucun endpoint qui ne soit pas utilisé en production.

**Hors périmètre** : les notifications push mobiles (FCM/APNs) n'ont rien à voir
avec Airtel — c'est `PushNotifier`/`FcmService`, côté Firebase. Et l'API Tonji
elle-même (`/api/admin`, `/api/mobile`) vit dans l'autre collection,
`tonji.postman_collection.json`.

## Ce qui est automatisé

- **Jeton OAuth** — obtenu au besoin par le script de pré-requête de la
  collection, gardé **160 s** comme le backend (l'API l'expire à 170 s). Aucune
  étape de connexion à jouer, aucune variable à coller à la main.
- **Références de transaction** — générées avec les préfixes du registre
  (`App\Support\Registre::PREFIXES`) : `TONJIPAYIN…` pour un encaissement,
  `TONJIPAYOUT…` pour un décaissement, `TONJIDISBURSEMENT<ms>` comme référence
  opérateur. Neuf caractères alphanumériques majuscules, sans tiret — l'API
  refuse les tirets.
- **Routage B2C / B2B** — le `grade` rendu par le KYC pose `type_decaissement`
  pour les requêtes suivantes, selon la même règle que `resolveDisburseType()` :
  `SUBS`/`TEMP` → particulier → **B2C**, tout autre grade → entreprise → **B2B**.
  Un grade inconnu des deux listes fait tomber un test, parce qu'il doit faire
  bloquer une inscription.
- **Attente du résultat d'un push** — la requête « Statut — attente du résultat »
  se rejoue tant que l'opérateur répond `PENDING`, jusqu'à `statut_max_essais`
  (20 par défaut). Le délai entre deux passages se règle dans l'outil :
  Runner → champ **Delay** `3000` ms, newman → `--delay-request 3000`.
- **Pré-remplissage** — le push part avec le nom et le prénom lus au KYC.

## Voir la réponse brute du serveur

La collection est livrée en **mode brut** : `mode_brut = oui`. Dans cet état,
aucune vérification n'est jouée — la réponse de l'API passe telle quelle — et
`tracer_brut = oui` écrit dans la console, pour chaque requête :

```
POST https://api.paynala.com/functions/v1/kyc
en-têtes envoyés : {"Authorization":"«masqué»","Content-Type":"application/json"}
corps envoyé : {"msisdn":"077730634"}
→ HTTP 200 OK en 412 ms
→ réponse : {"success":false,"message":"merchant not active"}
```

- **Bruno** : onglet *Timeline* de la requête (requête et réponse telles
  qu'envoyées sur le fil) et panneau *Console*. L'onglet *Response* montre de
  toute façon la réponse non modifiée — un script ne la touche jamais.
- **`bru run`** : les `console.log` sortent dans le flux normal.
- **Postman** : `View → Show Postman Console`.
- **newman** : `--verbose` ajoute en-têtes et temps de réponse de chaque appel.

`mode_brut = non` rallume les vérifications (grades KYC connus, `success`,
idempotence, statut final). L'**enchaînement des variables fonctionne dans les
deux modes** : le nom du titulaire, le routage B2C/B2B et le `request_id` sont
posés même en mode brut — sinon la collection ne serait plus automatique.

`tracer_brut = non` coupe la trace si la console devient illisible.

Les en-têtes `Authorization` et `x-operator-key` sortent **masqués** : une trace
finit souvent recopiée dans un ticket.

## Garde-fous

| Variable | Défaut | Effet |
|---|---|---|
| `plafond_montant_test` | `500` | Au-delà, le push ou le décaissement **ne part pas**. |
| `autoriser_decaissement` | `non` | Tant que ce n'est pas `oui`, les deux requêtes du dossier 3 **ne partent pas**. |

Postman saute la requête, Bruno la marque en erreur avec la raison : dans les
deux cas, rien ne sort sur le réseau.

Conséquence voulue : **lancer la collection entière ne sort jamais d'argent**.
Elle vérifie le KYC, pousse un paiement de 100 FCFA et attend son résultat ; les
deux requêtes de décaissement demandent une autorisation explicite, à remettre à
`non` après.

## Mise en route

### Bruno

```
Bruno → Open Collection → backend/docs/bruno/tonji-airtel
```

Pas d'import : Bruno lit le dossier tel quel, et les `.bru` se relisent dans un
diff Git. Sélectionner ensuite l'environnement **api-paynala-reel** et y coller
trois valeurs, telles qu'elles sont dans le `.env` du backend :

| Variable de la collection | Variable backend |
|---|---|
| `airtel_client_id` | `PAYNALA_CLIENT_ID` |
| `airtel_client_secret` | `PAYNALA_CLIENT_SECRET` |
| `airtel_operator_key` | `PAYNALA_OPERATOR_KEY` |

Elles sont déclarées en `vars:secret` : Bruno les garde hors du fichier, donc
rien de confidentiel n'entre dans le dépôt.

Le jeton s'obtient tout seul via `bru.runRequest`, disponible à partir de
**Bruno 1.28**. Sur une version plus ancienne, un avertissement s'affiche en
console : lancer une fois `0 · Jeton`, il vaut 160 s.

En ligne de commande, depuis `docs/bruno/tonji-airtel` :

```bash
npm install -g @usebruno/cli

# Scénario complet sans sortie d'argent (jeton → KYC → push → attente)
bru run --env api-paynala-reel -r

# Un seul dossier
bru run 1-kyc --env api-paynala-reel

# Rallumer les vérifications automatiques
bru run --env api-paynala-reel -r --env-var mode_brut=non

# Décaissement réel : autorisation explicite, à ne pas laisser dans un alias
bru run 3-decaissement --env api-paynala-reel \
  --env-var autoriser_decaissement=oui --env-var montant_decaissement=100
```

### Postman

```
Postman → Import → les deux fichiers de backend/docs/postman/
  tonji-airtel.postman_collection.json
  tonji-airtel.postman_environment.json
```

Mêmes trois secrets à renseigner dans l'environnement importé.

```bash
npm install -g newman

# Scénario complet sans sortie d'argent
newman run docs/postman/tonji-airtel.postman_collection.json \
  -e docs/postman/tonji-airtel.postman_environment.json --delay-request 3000

# Voir tout ce qui passe sur le fil (en-têtes, temps, corps)
newman run docs/postman/tonji-airtel.postman_collection.json \
  -e docs/postman/tonji-airtel.postman_environment.json --verbose

# Rallumer les vérifications automatiques
newman run docs/postman/tonji-airtel.postman_collection.json \
  -e docs/postman/tonji-airtel.postman_environment.json --env-var mode_brut=non
```

### Dans les deux cas

`airtel_msisdn` attend un **numéro local à 9 chiffres** (`077730634`), jamais de
l'E.164 : c'est le format que l'API Airtel accepte. Le backend fait la
conversion `+241XXXXXXXX` → `0XXXXXXXX` avant chaque appel.

Les identifiants ne sont **pas** versionnés. En intégration continue, les passer
par `--env-var` depuis les secrets du dépôt, jamais dans un fichier.

## Pièges que la collection rend visibles

- **`request_id` sans tiret**, 4 à 64 caractères. Un UUID avec tirets est refusé.
- **404 sur le statut** = l'API ne connaît pas ce `request_id` : le paiement n'a
  jamais existé. Le backend le traduit en `FAILED`, pas en erreur.
- **« Transaction Ambiguous »** sur un décaissement = `type` qui ne correspond
  pas au compte (B2B vers un particulier, ou l'inverse). Le test l'explique dans
  la console et rappelle la valeur de `type_decaissement` utilisée.
- **Jeton frais pour `disburse`** : cet endpoint rejette les jetons gardés trop
  longtemps. Le backend vide son cache avant chaque appel ; ici le cache de
  160 s suffit, mais une erreur d'authentification sur `disburse` seul se
  regarde de ce côté.
- **`merchant not active`** : réponse de **Paynala**, pas un contrôle de la
  collection. Le profil marchand derrière les identifiants utilisés n'est pas
  activé pour l'endpoint appelé. Le backend la classe dans son troisième cas,
  « service indisponible » — c'est exactement ce qui fait répondre
  « KYC indisponible » à l'app sans bloquer l'inscription. À vérifier dans cet
  ordre : la paire `client_id` / `client_secret` (celle de production n'est pas
  celle de la recette), puis `x-operator-key` pour `disburse`. Rien à corriger
  dans la collection.
- **Réponse `200` avec `success: false`** : l'API répond en 200 sur des erreurs
  métier. Tous les tests vérifient les deux, comme le backend.
- **Idempotence** : la requête de rejeu renvoie volontairement la dernière clé
  acceptée. L'API doit rendre la même transaction ou refuser le doublon — jamais
  sortir l'argent une seconde fois.

## À demander à Paynala

L'adresse d'un **vrai environnement de test**. Tant qu'elle n'existe pas, toute
vérification de bout en bout se fait sur de l'argent réel, et cette collection
ne peut pas tourner en intégration continue sans débiter un compte.
