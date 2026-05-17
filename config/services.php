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

    // Snelstart-webhook-config is door emeq/snelstart-api SDK geleverd onder
    // `config('snelstart.webhook.*')`. SDK leest de SNELSTART_WEBHOOK_* env-
    // vars direct; geen Hub-side duplicatie.

    'mollie' => [
        'connect' => [
            'client_id' => env('MOLLIE_CONNECT_CLIENT_ID'),
            'client_secret' => env('MOLLIE_CONNECT_CLIENT_SECRET'),
            'redirect_uri' => env('MOLLIE_CONNECT_REDIRECT_URI'),
            'scopes' => [
                'payments.read',
                'payments.write',
                'customers.read',
                'customers.write',
                'subscriptions.read',
                'subscriptions.write',
                'mandates.read',
                'organizations.read',
                'onboarding.read',
            ],
        ],
        'webhook_secret' => env('MOLLIE_WEBHOOK_SECRET'),
    ],

    'cashier' => [
        // Plan 06-06 leest dit voor de stap-0 hard-fail Cashier-webhook-guard.
        // Empty/null → 500 + audit-row (analoog aan Phase 5a D-08 stap 1).
        'webhook_secret' => env('CASHIER_WEBHOOK_SECRET'),
    ],

];
