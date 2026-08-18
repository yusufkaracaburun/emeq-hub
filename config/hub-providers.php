<?php

declare(strict_types=1);

return [
    'mollie' => [
        'encrypted_fields' => ['access_token', 'refresh_token'],
        'primary_label' => 'OAuth token',
        'oauth_flow_key' => 'mollie',
    ],
    'snelstart' => [
        'encrypted_fields' => ['client_key', 'subscription_key'],
        'primary_label' => 'Client key',
        'oauth_flow_key' => null,
    ],
    'exact' => [
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
