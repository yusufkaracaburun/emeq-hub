<?php

declare(strict_types=1);

namespace App\Billing\Account\Dto;

use DateTimeInterface;

/**
 * Read-only DTO voor AccountSubscriptionManager::create() input.
 *
 * Plain PHP-readonly-class (D-13 + minimal-deps-stance) — geen Spatie-Data
 * laag. Plan 07-04's Form Request mapt body → DTO.
 *
 * `amount_value` is een Mollie-conforme decimal-string (bv. "10.00"); NOOIT
 * casten naar float (zie D-03 + 07-03 plan must-haves).
 */
final readonly class CreateAccountSubscriptionDto
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $mollieCustomerId,
        public ?string $mollieMandateId,
        public string $amountCurrency,
        public string $amountValue,
        public string $interval,
        public string $description,
        public ?int $times,
        public ?DateTimeInterface $startDate,
        public ?array $metadata,
    ) {}
}
