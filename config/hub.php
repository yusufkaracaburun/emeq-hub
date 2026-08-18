<?php

return [

    'retention' => [
        'pass_through_days' => (int) env('RETENTION_PASS_THROUGH_DAYS', 90),
        'webhook_days' => (int) env('RETENTION_WEBHOOK_DAYS', 90),
    ],

    'idempotency' => [
        'lease_seconds' => (int) env('IDEMPOTENCY_LEASE_SECONDS', 900),
        'retention_hours' => (int) env('IDEMPOTENCY_RETENTION_HOURS', 24),
    ],

    'rate_limits' => [
        'writes_per_minute' => (int) env('RATE_LIMIT_WRITES_PER_MINUTE', 120),
        'reads_per_minute' => (int) env('RATE_LIMIT_READS_PER_MINUTE', 300),
    ],

];
