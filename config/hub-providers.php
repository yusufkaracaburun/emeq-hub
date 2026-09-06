<?php

declare(strict_types=1);

return [
    'mollie' => [
        'enabled' => env('HUB_PROVIDER_MOLLIE_ENABLED', false),
        'encrypted_fields' => ['access_token', 'refresh_token'],
        'primary_label' => 'OAuth token',
        'oauth_flow_key' => 'mollie',
    ],
    'snelstart' => [
        'enabled' => env('HUB_PROVIDER_SNELSTART_ENABLED', false),
        'encrypted_fields' => ['client_key', 'subscription_key'],
        'primary_label' => 'Client key',
        'oauth_flow_key' => null,
    ],
    'dataforseo' => [
        'enabled' => env('HUB_PROVIDER_DATAFORSEO_ENABLED', false),
        'encrypted_fields' => ['access_token'],
        'primary_label' => 'API key (login:password)',
        'oauth_flow_key' => null,
    ],
    'itheorie' => [
        'enabled' => env('HUB_PROVIDER_ITHEORIE_ENABLED', false),
        'encrypted_fields' => [],
        'primary_label' => 'Broker-inlog (Hub-eigen)',
        'oauth_flow_key' => null,
    ],
    'exact' => [
        'enabled' => env('HUB_PROVIDER_EXACT_ENABLED', true),
        'encrypted_fields' => ['access_token', 'refresh_token'],
        'primary_label' => 'OAuth token',
        'oauth_flow_key' => 'exact',
        'error_budget' => [
            'enabled' => true,
            'threshold' => 6,
            'window' => 3600,
        ],
        'allowed_paths' => [
            'crm/Accounts',
            'financial/GLAccounts',
            'financial/Journals',
            'financial/CostCenters',
            'financial/CostUnits',
            'vat/VATCodes',
            'salesentry/SalesEntries',
            'purchaseentry/PurchaseEntries',
            'documents/Documents',
            'documents/DocumentAttachments',
            'financialtransaction/BankEntries',
            'financialtransaction/CashEntries',
            'generaljournalentry/GeneralJournalEntries',
            'webhooks/WebhookSubscriptions',
        ],
    ],
];
