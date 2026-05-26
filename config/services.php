<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OTP — driver de génération/vérification
    |--------------------------------------------------------------------------
    |
    | `dev`    : OTP statique « 123456 » accepté tel quel. Gratuit, instantané,
    |            renvoyé dans la réponse de request-otp via dev_hint. Mode
    |            par défaut en local.
    | `twilio` : Twilio Verify — vrai SMS envoyé via l'API Verify. Le code
    |            est généré et stocké par Twilio (pas par Laravel), avec
    |            rate-limit anti-brute-force et expiration auto à 10 min.
    |
    */
    'otp' => [
        'driver' => env('OTP_DRIVER', 'dev'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Paynala Payment Gateway — Airtel Money Gabon
    |--------------------------------------------------------------------------
    |
    | PAYNALA_BASE_URL : staging = https://testapi.paynala.com/functions/v1
    |                    prod    = https://api.paynala.com/functions/v1
    |
    */
    'paynala' => [
        'client_id'     => env('PAYNALA_CLIENT_ID'),
        'client_secret' => env('PAYNALA_CLIENT_SECRET'),
        'base_url'      => env('PAYNALA_BASE_URL', 'https://testapi.paynala.com/functions/v1'),
        'operator_key'  => env('PAYNALA_OPERATOR_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OneSignal — Push notifications mobile
    |--------------------------------------------------------------------------
    |
    | App ID   : onglet "Keys & IDs" du dashboard OneSignal.
    | REST Key : REST API Key (à ne pas confondre avec le User Auth Key).
    |
    */
    'onesignal' => [
        'app_id'       => env('ONESIGNAL_APP_ID'),
        'rest_api_key' => env('ONESIGNAL_REST_API_KEY'),
    ],

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'verify_service_sid' => env('TWILIO_VERIFY_SERVICE_SID'),
        // Si défini, tous les OTP Twilio sont envoyés à ce numéro
        // (au lieu du numéro saisi). Workaround pour le compte trial
        // qui n'autorise que les Verified Caller IDs. L'user qui tape
        // son numéro côté app reste enregistré avec SON numéro en DB ;
        // seul l'appel Twilio est redirigé. À retirer dès qu'on passe
        // en compte payant.
        'override_recipient' => env('TWILIO_OVERRIDE_RECIPIENT'),
    ],

];
