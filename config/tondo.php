<?php

return [
    /*
     * Numéro WhatsApp du bot (sans + ni espaces) — ex : 24166XXXXXX.
     * Utilisé pour générer les liens wa.me dans les messages de création.
     * Définir TONJI_BOT_WA_NUMERO dans .env.
     */
    'whatsapp_numero' => env('TONJI_BOT_WA_NUMERO', ''),

    /*
     * Secret partagé avec la passerelle USSD de l'opérateur.
     * Toutes les requêtes USSD doivent porter l'entête X-Ussd-Secret
     * dont la valeur correspond à cette variable.
     * Définir USSD_SECRET dans .env (chaîne aléatoire longue, min 32 chars).
     * Si vide, les routes USSD retournent 401 pour toutes les requêtes.
     */
    'ussd_secret' => env('USSD_SECRET', ''),
];
