<?php

declare(strict_types=1);
use Emeq\MollieApi\Idempotency\UuidV7IdempotencyKeyGenerator;

return [
    // SDK gebruikt deze generator wanneer geen consumer-Idempotency-Key
    // is gezet (D-06). Per-Connection-context wordt door
    // ResolveMollieAccount-middleware ingevuld (D-03).
    'idempotency' => [
        'generator' => UuidV7IdempotencyKeyGenerator::class,
    ],

    // Facade-alias om collision met laravel-mollie te voorkomen (Phase 6 Cashier).
    'facade_alias' => 'EmeqMollie',

    // Production-guard tegen test_-prefix in production env (later in v0.3).
    'enforce_environment' => env('MOLLIE_ENFORCE_ENVIRONMENT', false),
];
