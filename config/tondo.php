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
     * Feature flag « Retrait en espèces » — le canal agent, où un tiers remet
     * des BILLETS contre le solde d'une collecte.
     *
     * À false (défaut) : les routes `/api/agent/*` et celles qui administrent
     * supports, partenaires et agents **ne sont pas enregistrées**. Elles
     * répondent 404, pas 401 : le canal est absent, pas seulement gardé. Le
     * dashboard masque les entrées correspondantes (`RETRAIT_ESPECES_ACTIF`),
     * et `tonji:audit` échoue si les deux valeurs divergent.
     *
     * Pourquoi ce drapeau : le chantier est expérimental et ses contours ne
     * sont pas arrêtés, alors qu'il DÉBITE une collecte et remet du liquide —
     * ce qui ne se conteste pas, contrairement à un virement. Il part pourtant
     * en production avec le chantier marchand, dont il est inséparable : les
     * deux se sont succédé sur la même ligne d'historique, et 14 fichiers du
     * chemin de l'argent leur sont communs. Les isoler reviendrait à écrire du
     * code neuf, jamais exécuté, là où l'argent passe.
     *
     * Décision de Daniel (2026-10-06) : drapeau + masquage plutôt qu'une
     * branche reconstruite.
     *
     * ⚠️ Avant de passer ce drapeau à true, poser le verrou des sorties dans
     * `RetraitEspecesService` — voir le commentaire de sa méthode `demander()`.
     */
    'retrait_especes_actif' => env('TONJI_RETRAIT_ESPECES_ACTIF', false),

];
