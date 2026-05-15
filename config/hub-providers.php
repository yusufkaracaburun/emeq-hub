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
];
