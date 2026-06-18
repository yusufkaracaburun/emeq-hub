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

    'mollie' => [
        'partner_access_token' => env('MOLLIE_PARTNER_ACCESS_TOKEN'),
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
    ],

    'cashier' => [
        'webhook_secret' => env('CASHIER_WEBHOOK_SECRET'),
    ],

    'exact' => [
        // Credentials komen uit de DB-settings (ExactSettings) via
        // SettingsHydrationServiceProvider — niet uit .env. Vul ze in de admin
        // (Beheer → Integratie-instellingen). Base-URLs houden een statische
        // default (geen secret, geen env).
        'client_id' => null,
        'client_secret' => null,
        'redirect_uri' => null,
        'webhook_secret' => null,
        'auth_base_url' => 'https://start.exactonline.nl',
        'api_base_url' => 'https://start.exactonline.nl',

        // Topics waarop de Hub per Exact-Connection abonneert bij OAuth-connect
        // (Hub-orchestratie, niet SDK-protocol). Verifieer de exacte strings via
        // GET webhooks/WebhookTopics (ListWebhookTopics). Nieuwe topic = regel hier.
        'webhook_topics' => [
            'FinancialTransactions',
            'Documents',
            'Accounts',
            'Items',
        ],
    ],

];
