<?php

/**
 * Retrait en espèces chez un agent partenaire.
 *
 * Réglages rassemblés ici plutôt que dispersés dans le code : ce sont eux qu'on
 * ajustera avec le partenaire, et chacun a une conséquence sur la sécurité.
 */
return [

    // Code envoyé par SMS au numéro de retrait. Six chiffres et trois essais :
    // une chance sur 333 000 pour qui connaît le numéro de cagnotte, public,
    // mais pas le téléphone du titulaire.
    'code_longueur'      => 6,
    'code_tentatives'    => 3,
    'code_validite_min'  => (int) env('RETRAIT_CODE_VALIDITE_MIN', 10),

    // Durée d'une session d'agent : une journée de travail au comptoir. Au-delà,
    // un terminal oublié allumé ne reste pas utilisable.
    'session_heures'     => (int) env('RETRAIT_SESSION_HEURES', 12),

    // Les journées de caisse et les plafonds journaliers se comptent à l'heure
    // de Libreville, pas en UTC : un retrait à 23 h y appartient au bon jour.
    'fuseau'             => 'Africa/Libreville',

    // Garde-fou contre le harcèlement par SMS d'un titulaire, et contre le
    // sondage des soldes : demandes tolérées par agent sur une même cagnotte.
    'demandes_par_heure' => (int) env('RETRAIT_DEMANDES_PAR_HEURE', 5),

    // Numéro cité dans le SMS de confirmation (« Pas vous ? Appelez le … »).
    // Vide : la phrase est omise plutôt que de pointer vers un numéro faux.
    'numero_assistance'  => env('RETRAIT_NUMERO_ASSISTANCE'),

    // ⚠️ ENVIRONNEMENT DE TEST UNIQUEMENT. Détourne tous les SMS de retrait vers
    // ce numéro : la base de test contient des copies de numéros réels, qu'un
    // essai ne doit jamais atteindre. Ignoré en production, quelle que soit la
    // valeur de la variable.
    'sms_destinataire_force' => env('RETRAIT_SMS_DESTINATAIRE_FORCE'),
];
