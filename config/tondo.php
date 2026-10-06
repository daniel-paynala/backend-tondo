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

    /*
     * Feature flag « Cagnotte d'abord » — active ou non les parcours TONTINE.
     *
     * Au lancement, Tonji se positionne sur la cagnotte uniquement : la tontine
     * est repoussée à une phase ultérieure. Ce flag est le pendant backend de
     * `kTontinesActives` (Flutter) et `TONTINES_ACTIVES` (web) : les trois
     * canaux doivent TOUJOURS être alignés.
     *
     * À false (défaut) :
     *   - le bot WhatsApp ne propose plus de CRÉER une tontine (seul point où
     *     une tontine peut naître) ;
     *   - le lexique affiché est 100 % cagnotte.
     * Le code des parcours tontine n'est PAS supprimé : il reste en place et
     * redevient atteignable en passant TONJI_TONTINES_ACTIVES=true dans .env.
     * Les tontines déjà en base restent gérables dans tous les cas.
     */
    'tontines_actives' => env('TONJI_TONTINES_ACTIVES', false),

    /*
     * Feature flag « Payer un commerce » — le client envoie le solde d'une
     * collecte chez un marchand enregistré au lieu de le transférer à une
     * personne.
     *
     * Pendant unique de `kPaiementMarchandActif` (Flutter) et
     * `PAIEMENT_MARCHAND_ACTIF` (web) : les trois canaux doivent TOUJOURS être
     * alignés, et `tonji:audit` le vérifie. Un drapeau ouvert d'un côté et
     * fermé de l'autre donne une interface qui propose ce que le serveur
     * refuse — ou l'inverse, une fonction accessible sans l'avoir décidé.
     *
     * À false (défaut) : le bouton « Payer » n'est pas construit dans l'app ni
     * sur le web, et le bot WhatsApp ne propose pas l'entrée correspondante.
     * Les fiches marchandes et le portail restent administrables : seule
     * l'entrée côté client disparaît.
     */
    'paiement_marchand_actif' => env('TONJI_PAIEMENT_MARCHAND_ACTIF', false),
];
