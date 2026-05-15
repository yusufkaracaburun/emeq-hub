<?php

declare(strict_types=1);

/*
 * D-15: admin-billing-routes worden gated via een config-allowlist
 * tot Phase 9 Filament-panel landt. Empty allowlist = niemand is admin
 * = alle admin-routes 403 (default-deny).
 */
return [
    'admin_allowlist' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('BILLING_ADMIN_CONSUMER_IDS', '')),
    ))),

    'default_subscription_name' => env('BILLING_DEFAULT_SUBSCRIPTION_NAME', 'main'),
];
