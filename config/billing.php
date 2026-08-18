<?php

declare(strict_types=1);

return [
    'admin_allowlist' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('BILLING_ADMIN_CONSUMER_IDS', '')),
    ))),

    'default_subscription_name' => env('BILLING_DEFAULT_SUBSCRIPTION_NAME', 'main'),
];
