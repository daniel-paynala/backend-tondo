<?php

use App\Models\TondoAdmin;
use App\Models\TondoUser;

return [
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'admin'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'admins'),
    ],

    'guards' => [
        // Guard token-based pour le dashboard admin Next.js (Sanctum).
        'admin' => [
            'driver' => 'sanctum',
            'provider' => 'admins',
        ],

        // Guard token-based pour l'app mobile (Sanctum + OTP statique en
        // dev). Plus tard : à remplacer par un middleware Supabase JWT
        // qui hydrate $request->user() depuis le JWT phone-OTP Supabase.
        'mobile' => [
            'driver' => 'sanctum',
            'provider' => 'mobile_users',
        ],
    ],

    'providers' => [
        'admins' => [
            'driver' => 'eloquent',
            'model' => TondoAdmin::class,
        ],

        'mobile_users' => [
            'driver' => 'eloquent',
            'model' => TondoUser::class,
        ],
    ],

    'passwords' => [
        'admins' => [
            'provider' => 'admins',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
