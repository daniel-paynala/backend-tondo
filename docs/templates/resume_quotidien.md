# Template WhatsApp — `resume_quotidien`

Notification proactive du soir envoyée aux créateurs de cagnottes (1 seul message
par créateur, listant toutes ses cagnottes ayant reçu une cotisation dans la
journée). Envoyé par la commande `tonji:resume-quotidien` (cron 20h Libreville).

## À créer chez Meta (WhatsApp Manager → Modèles de messages)

- **Nom** : `resume_quotidien` (exactement — c'est la valeur de `TONJI_TEMPLATE_RESUME_QUOTIDIEN`)
- **Catégorie** : **UTILITY** (transactionnel — tarif le moins cher)
- **Langue** : **Français (fr)**

### Corps (Body)
```
Bonsoir {{1}} 👋
Voici tes cagnottes qui ont reçu aujourd'hui :
{{2}}
Total reçu : {{3}} FCFA. 🎉 Bonne soirée !
```

### Variables (exemples à fournir à Meta pour la validation)
| Variable | Sens | Exemple |
|----------|------|---------|
| `{{1}}` | Prénom du créateur | `Daniel` |
| `{{2}}` | Détail **une ligne par cagnotte** (puces) | `• Maman : 5 000 FCFA (2)`<br>`• Papa : 7 000 FCFA (1)` |
| `{{3}}` | Total reçu dans la journée (FCFA) | `12 000` |

> Note : `{{2}}` contient **une ligne par cagnotte** (retours à la ligne). Meta
> accepte généralement les `\n` dans un paramètre de template ; si un envoi réel
> est refusé pour ce motif, repasser à un séparateur « · » (une ligne).
> Le code plafonne à **5 cagnottes** détaillées puis ajoute « et N autre(s) ».

## Une fois approuvé
Dans le `.env` du serveur :
```
TONJI_TEMPLATE_RESUME_QUOTIDIEN=resume_quotidien
# WHATSAPP_TEMPLATE_LANG=fr   (déjà la valeur par défaut)
```
Puis `php artisan config:clear`. La commande est planifiée à **20h** (Africa/Libreville)
dans `routes/console.php`. Test manuel sans envoi : `php artisan tonji:resume-quotidien --dry-run`.
