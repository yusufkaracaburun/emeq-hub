<?php

declare(strict_types=1);

/*
 * D-04: ProviderCredentialDescriptor declarations. Eén rij per provider.
 *
 * Filament's ConnectionResource leest dit voor per-provider conditional
 * form-sections, fingerprint-resolution en revoke-action visibility.
 * Connection::fingerprint() leest dit via ProviderCredentialDescriptor::for()
 * om provider-specifieke primary-credential te selecteren zonder match-arm.
 *
 * Een nieuwe provider toevoegen vereist alleen een rij hier — geen Filament-
 * code-wijziging (zie 09-CONTEXT.md success-criterium 10).
 *
 * encrypted_fields: list<string> van Connection-attributen die als
 *   encrypted-credentials gelden voor deze provider; eerste element is
 *   de "primary" credential die fingerprint() hasht.
 * primary_label: human-readable label voor de fingerprint-kolom-header.
 * oauth_flow_key: matches OAuthFlowRegistry::register(...)-key, of null
 *   wanneer provider géén OAuth-flow heeft (Snelstart's clientkey/subscription).
 * error_budget: optioneel per-provider — pass-through-circuit-breaker (enabled,
 *   threshold, window-seconden). Alleen Exact heeft een gedeelde error-key-limiet
 *   die een breaker rechtvaardigt; gelezen door App\Support\Exact\ExactErrorBudget.
 * allowed_paths: optioneel — whitelist van OData-resource-paden die de generieke
 *   pass-through mag benaderen (App\Support\Exact\ExactPathWhitelist). Spiegelt de
 *   App-Center-scope-matrix (docs/exact/data-security-answers.md). Lege lijst =
 *   whitelist uit (alles door) → kill-switch bij een consumer-breuk.
 */
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
            'threshold' => 6,   // trip ruim onder Exact's 10/uur/endpoint
            'window' => 3600,   // seconden — rollend uur-venster vanaf de eerste fout
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
            'webhooks/WebhookSubscriptions',
        ],
    ],
];
