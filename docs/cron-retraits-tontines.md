# Cron — Retraits automatiques des tontines périodiques

## Vue d'ensemble

Chaque jour à **20h00 heure de Libreville** (Africa/Libreville, UTC+1), Laravel
exécute la commande `tontines:traiter-retraits`. Elle parcourt toutes les tontines
`en_cours`, identifie celles dont l'échéance tombe ce jour, et vire la mise au
bénéficiaire du cycle courant via l'API Paynala.

---

## Règles métier appliquées

| Règle | Comportement |
|---|---|
| Tous les participants doivent avoir cotisé | Si un seul n'a pas payé → retrait suspendu, email au gérant + admins |
| Bénéficiaire = participant avec `ordre_passage = cyclesCompletes + 1` | Déterminé depuis la DB, pas depuis l'UI |
| Numéro de réception = `users.numero` du bénéficiaire | Numéro KYC vérifié à l'inscription (Option B) |
| Solde insuffisant | Retrait annulé, log d'erreur |
| Paynala indisponible | Email d'alerte aux admins, **solde non restauré automatiquement** (intervention manuelle requise) |
| Rotation terminée (`cyclesCompletes >= nombre_inscrits`) | Tontine ignorée silencieusement |

---

## Flux détaillé

```
20h00 → tontines:traiter-retraits
│
├── Pour chaque tontine en_cours :
│   │
│   ├── [1] Calculer prochaineDate(cagnotte, cyclesCompletes)
│   │       ≠ aujourd'hui → skip
│   │
│   ├── [2] Tous les participants ont statut_paiement = 'paye' ?
│   │       NON → email gérant + admins → skip
│   │
│   ├── [3] Trouver bénéficiaire (ordre_passage = cyclesCompletes + 1)
│   │
│   ├── [4] DB transaction + row-lock :
│   │       - Vérifier solde >= montant_par_cycle
│   │       - INSERT tondo_payout (statut = 'initie')
│   │       - DECREMENT montant_collecte
│   │
│   ├── [5] Appel Paynala disburse()
│   │       KO → UPDATE payout 'echec' + email admins → skip
│   │
│   └── [6] DB transaction :
│           - UPDATE payout 'succes'
│           - RESET statut_paiement → 'en_attente' pour TOUS les participants
│           OneSignal → bénéficiaire "Votre mise versée"
│           OneSignal → autres "Nouveau cycle — cotisez"
│
└── Log : N retraits effectués, M ignorés
```

---

## Activation sur le serveur AWS

Ajouter une seule entrée cron (toutes les minutes, Laravel gère la planification) :

```bash
crontab -e
```

```cron
* * * * * php /var/www/html/artisan schedule:run >> /dev/null 2>&1
```

Vérifier que le scheduler tourne :

```bash
php artisan schedule:list
```

Sortie attendue :

```
tontines:traiter-retraits   Daily at 20:00  Africa/Libreville
```

---

## Tester sans virement réel

L'option `--dry-run` simule l'intégralité du flux (logs, vérifications) sans
toucher à la base de données ni appeler Paynala :

```bash
php artisan tontines:traiter-retraits --dry-run
```

Forcer une exécution immédiate (hors horaire planifié) :

```bash
php artisan tontines:traiter-retraits
```

---

## Emails d'alerte

### Cotisations incomplètes — `RetraitImpossibleMail`

Envoyé au **gérant de la tontine** (si email renseigné) et à tous les **admins actifs**
quand le retrait est suspendu faute de cotisations.

Contenu : liste des participants n'ayant pas cotisé (nom, numéro masqué), cycle
concerné, date prévue.

### Paynala indisponible — `DisbursementFailedMail`

Envoyé aux **admins actifs** quand l'appel API Paynala échoue après que les fonds
ont été réservés en DB.

Contient les requêtes SQL à exécuter manuellement selon le résultat de la vérification
côté Paynala (argent envoyé ou non).

---

## Gestion manuelle d'un incident Paynala

Si un payout reste en statut `echec` après une alerte :

**1. Vérifier dans Paynala** si la transaction `idempotency_key` a été exécutée.

**2a. Paynala a bien envoyé l'argent :**

```sql
UPDATE tondo_payout
SET statut = 'succes', updated_at = now()
WHERE id = '<payout_id>';
```

**2b. Paynala n'a rien envoyé :**

```sql
-- Restaurer le solde
UPDATE tondo_cagnottes
SET montant_collecte = montant_collecte + <montant>,
    updated_at = now()
WHERE reference = '<cagnotte_reference>';

-- Marquer l'échec définitif
UPDATE tondo_payout
SET statut = 'echec', updated_at = now()
WHERE id = '<payout_id>';
```

Puis relancer manuellement :

```bash
php artisan tontines:traiter-retraits
```

---

## Réconciliation

L'endpoint admin permet de vérifier l'intégrité de toutes les cagnottes :

```
GET /api/admin/reconcile
GET /api/admin/cagnottes/{reference}/reconcile
```

Un écart entre `montant_collecte` et `SUM(payins) - SUM(payouts)` indique une
anomalie à investiguer. Le détail inclut également les payouts/payins bloqués
en statut `initie` depuis plus de 10–15 minutes.

---

## Réinitialisation du cycle

Après chaque retrait réussi, la commande remet **tous les participants** à
`statut_paiement = 'en_attente'`. C'est ce champ qui permet à l'app mobile
d'afficher le statut "à cotiser" pour le cycle suivant, et qui débloque le
bouton "Cotiser" pour chaque participant.

Le compteur de cycles (`cyclesCompletes`) est déduit du nombre de payouts
`succes` sur la cagnotte — il n'y a pas de champ `cycle_numero` explicite
en base.

---

## Code source

| Fichier | Rôle |
|---|---|
| `app/Console/Commands/TraiterRetraitsTontines.php` | Commande principale |
| `app/Services/TontineService.php` | Calcul `prochaineDate()` partagé |
| `app/Mail/RetraitImpossibleMail.php` | Mail cotisations incomplètes |
| `app/Mail/DisbursementFailedMail.php` | Mail Paynala KO |
| `resources/views/mail/retrait-impossible.blade.php` | Template texte |
| `resources/views/mail/disbursement-failed.blade.php` | Template texte |
| `routes/console.php` | Planification `Schedule::command(…)->dailyAt('20:00')` |
