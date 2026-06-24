# API USSD — Cotisation via menu opérateur

> Canal permettant à un abonné Mobile Money de cotiser à une cagnotte ou tontine
> Tonji directement depuis un menu USSD, sans application ni internet.

---

## Configuration

Ajouter dans `.env` :

```env
USSD_SECRET=ton_secret_aleatoire_ici_32_chars_minimum
```

Toutes les requêtes doivent porter l'entête :

```
X-Ussd-Secret: <valeur de USSD_SECRET>
```

---

## API 1 — Récupérer les infos d'une cagnotte

```
GET /api/ussd/cagnotte/{reference}?msisdn=+241770000000
```

**Paramètres :**

| Paramètre   | Type   | Obligatoire | Description                                    |
|-------------|--------|-------------|------------------------------------------------|
| `reference` | string | oui (path)  | Identifiant numérique court de la cagnotte     |
| `msisdn`    | string | oui (query) | Numéro Mobile Money du cotisant (E.164 ou local) |

**Réponse 200 — tontine périodique :**

```json
{
  "reference": "4821",
  "titre": "Tontine bureau mars",
  "type": "tontine_periodique",
  "statut": "en_cours",
  "montant_par_cycle": 5000,
  "numero_client": "+241770000000",
  "message": "Tontine « Tontine bureau mars ». Montant à cotiser : 5000 FCFA."
}
```

**Réponse 200 — cagnotte ouverte :**

```json
{
  "reference": "3104",
  "titre": "Cadeau Anna",
  "type": "cagnotte_ouverte",
  "statut": "en_cours",
  "montant_min": 100,
  "numero_client": "+241770000000",
  "message": "Cagnotte « Cadeau Anna ». Saisissez le montant (minimum 100 FCFA)."
}
```

**Codes d'erreur :**

| Code | Cas                                |
|------|------------------------------------|
| 401  | Secret USSD manquant ou incorrect  |
| 404  | Référence inconnue                 |
| 422  | Cagnotte non active (brouillon ou clôturée) |

---

## API 2 — Initier la cotisation

```
POST /api/ussd/cotiser
Content-Type: application/json
```

**Corps de la requête :**

```json
{
  "reference": "4821",
  "msisdn": "+241770000000",
  "montant": 5000
}
```

| Champ       | Type    | Obligatoire | Description                          |
|-------------|---------|-------------|--------------------------------------|
| `reference` | string  | oui         | Identifiant numérique de la cagnotte |
| `msisdn`    | string  | oui         | Numéro Mobile Money du cotisant      |
| `montant`   | integer | oui         | Montant en FCFA (entier positif)     |

**Règles de validation :**

- **Tontine périodique** : `montant` doit être **exactement** égal à `montant_par_cycle`. Tout écart retourne 422.
- **Cagnotte ouverte** : `montant` libre, mais **minimum 100 FCFA**.

**Réponse 200 — succès :**

```json
{
  "succes": true,
  "message": "Paiement initié. Confirmez la demande sur votre téléphone.",
  "transaction": { ... }
}
```

**Codes d'erreur :**

| Code | Cas                                                        |
|------|------------------------------------------------------------|
| 401  | Secret USSD incorrect                                      |
| 404  | Cagnotte inconnue                                          |
| 422  | Montant incorrect (tontine) ou inférieur à 100 (cagnotte)  |
| 500  | Erreur interne paiement                                    |

**Exemple d'erreur montant tontine :**

```json
{
  "erreur": "Montant incorrect. La tontine « Tontine bureau mars » impose exactement 5000 FCFA par cotisation.",
  "attendu": 5000,
  "recu": 3000
}
```

---

## Comportement utilisateur inconnu

Si le `msisdn` ne correspond à aucun compte Tonji, un **compte USSD light** est créé automatiquement. Ce compte est enrichi plus tard si l'utilisateur s'inscrit via l'app ou WhatsApp.

---

## Fichiers concernés

| Fichier | Rôle |
|---|---|
| `app/Http/Controllers/Api/Ussd/UssdController.php` | Controller des deux routes |
| `routes/api.php` | Routes sous préfixe `/api/ussd/` |
| `config/tondo.php` | Clé `ussd_secret` (lue depuis `USSD_SECRET` dans `.env`) |
