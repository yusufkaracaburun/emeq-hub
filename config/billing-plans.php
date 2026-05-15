<?php

declare(strict_types=1);

/*
 * D-05 / D-06: plan-definities voor Cashier-Mollie use-case A
 * (Emeq factureert aan Consumers). Schema matched
 * `mollie/laravel-cashier-mollie ^2.20`'s plan-shape:
 * - amount.value: string, 2 decimals (Mollie-validatie-vereiste)
 * - amount.currency: 'EUR' (multi-currency expliciet uit scope v0.2)
 * - interval: Cashier-Mollie ondersteunt '1 month', '12 months',
 *   '1 year', etc. — zie Cashier-Mollie SubscriptionBuilder.
 * - description: verschijnt op Mollie's invoice-emails.
 *
 * Plan 06-05 executor vult de echte prijzen in (uit business);
 * placeholders blijven hier op '0.00'. Op '0.00' weigert Mollie
 * de subscription-create, wat een safety-net is tegen per-ongeluk
 * deploy zonder bizz-input.
 */
return [
    'naschool-license' => [
        'amount' => [
            'value' => '0.00',
            'currency' => 'EUR',
        ],
        'interval' => '1 month',
        'description' => 'Naschool SaaS license — Emeq Hub access',
    ],
    'planny-license' => [
        'amount' => [
            'value' => '0.00',
            'currency' => 'EUR',
        ],
        'interval' => '1 month',
        'description' => 'Planny SaaS license — Emeq Hub access',
    ],
];
