<?php

/*
|----------------------------------------------------------------------------
| Tarification opérateur Mobile Money — valeurs par défaut (Airtel Gabon)
|----------------------------------------------------------------------------
| Source de vérité de fallback. La table tondo_project_config (dashboard
| admin) prime sur ces valeurs pour chaque projet qui a une config DB.
|
| Modèle A : le cotisant absorbe l'intégralité des frais (commission Paynala
| + frais de retrait). Le bénéficiaire reçoit exactement le montant annoncé.
*/

return [

    /*
    | Commission Paynala — appliquée sur le gross total envoyé au wallet
    | bénéficiaire (frais de retrait inclus). 0.02 = 2 %.
    | Exclue de la réponse mobile (backend-only).
    */
    'commission_paynala' => (float) env('PAYNALA_COMMISSION', 0.02),

    /*
    | Plafond d'un envoi Mobile Money unique (FCFA).
    */
    'plafond_par_envoi' => (int) env('AIRTEL_PLAFOND_ENVOI', 500_000),

    /*
    | Plafond journalier émetteur (FCFA). Informatif pour l'étalement.
    */
    'plafond_journalier' => (int) env('AIRTEL_PLAFOND_JOUR', 2_500_000),

    /*
    | Tranches de frais de retrait.
    |
    | Chaque tranche définit sa plage gross [montant_min, montant_max] :
    |   montant_min : null = pas de borne basse (dès 100 FCFA min Mobile Money)
    |   montant_max : null = pas de borne haute
    |
    | type "pourcentage" : frais = ceil(gross * valeur)
    | type "forfait"     : frais = valeur FCFA fixe
    |
    | 0 tranche = pas de frais de retrait.
    */
    'tranches' => [
        [
            'montant_min' => 100,
            'montant_max' => (int) env('AIRTEL_RETRAIT_SEUIL', 166_667),
            'type'        => 'pourcentage',
            'valeur'      => (float) env('AIRTEL_RETRAIT_TAUX', 0.03),
        ],
        [
            'montant_min' => (int) env('AIRTEL_RETRAIT_SEUIL', 166_667) + 1,
            'montant_max' => null,
            'type'        => 'forfait',
            'valeur'      => (int) env('AIRTEL_RETRAIT_FORFAIT', 5_000),
        ],
    ],

];
