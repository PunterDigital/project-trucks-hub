<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // Sign in with Steam (OpenID 2.0). STEAM_API_KEY is a Steam Web API key
    // (https://steamcommunity.com/dev/apikey), used to read the player's name and avatar.
    // Without it the Steam buttons are hidden.
    'steam' => [
        'client_id' => null,
        'client_secret' => env('STEAM_API_KEY') ?: null,
        'redirect' => env('STEAM_REDIRECT_URI') ?: rtrim((string) env('APP_URL'), '/').'/auth/steam/callback',
        // Refuse OpenID answers that return anywhere but this site.
        'allowed_hosts' => [parse_url((string) env('APP_URL'), PHP_URL_HOST) ?: 'localhost'],
        'force_https' => str_starts_with((string) env('APP_URL'), 'https://'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
