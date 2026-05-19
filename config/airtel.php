<?php

/*
|----------------------------------------------------------------------------
| Tarification Airtel Money + commission Paynala — Modèle A
|----------------------------------------------------------------------------
| Source de vérité des taux utilisés par App\Services\AirtelFeesCalculator
| et exposés au mobile via GET /api/mobile/config/frais.
|
| À TERME : ces valeurs migreront vers une table éditable depuis le dashboard
| admin (Next.js). Tant que cette table n'existe pas, on les centralise ici
| pour qu'un changement de taux = un seul endroit à toucher (pas de magic
| number dispersé dans le code mobile ou backend).
|
| Modèle A (décidé 2026-05-12) : le cotisant absorbe l'intégralité des frais
| (commission Paynala + frais de retrait Airtel). Le bénéficiaire reçoit en
| cash exactement le montant annoncé.
*/

return [

    /*
    | Commission Paynala — pourcentage appliqué sur le total envoyé au wallet
    | bénéficiaire (frais Airtel inclus). 0.02 = 2 %.
    */
    'commission_paynala' => (float) env('PAYNALA_COMMISSION', 0.02),

    /*
    | Plafond réseau d'un envoi Mobile Money unique (FCFA).
    */
    'plafond_par_envoi' => (int) env('AIRTEL_PLAFOND_ENVOI', 500_000),

    /*
    | Plafond journalier émetteur (FCFA). Informatif : si le total à envoyer
    | dépasse ce seuil, le décaissement doit être étalé sur plusieurs jours.
    */
    'plafond_journalier' => (int) env('AIRTEL_PLAFOND_JOUR', 2_500_000),

    /*
    | Grille de frais de retrait cash Airtel Money (Gabon).
    |  - Tranche 1 : envoi dans [100 ; seuil_tranche]      → taux_pourcentage
    |  - Tranche 2 : envoi dans [seuil_tranche+1 ; plafond] → forfait
    */
    'retrait' => [
        'seuil_tranche' => (int) env('AIRTEL_RETRAIT_SEUIL', 166_667),
        'taux_pourcentage' => (float) env('AIRTEL_RETRAIT_TAUX', 0.03),
        'forfait' => (int) env('AIRTEL_RETRAIT_FORFAIT', 5_000),
    ],

];
