<?php

return [
    /*
     * Code OTP universel pour les tests multi-utilisateurs.
     * Définir TONDO_OTP_BYPASS=123456 dans .env pour l'activer.
     * Laisser vide (ou ne pas définir) en production réelle.
     */
    'otp_bypass' => env('TONDO_OTP_BYPASS'),
];
