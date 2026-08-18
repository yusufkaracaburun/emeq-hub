<?php

declare(strict_types=1);

namespace App\Billing\Account\Dto;

use DateTimeInterface;

final readonly class CreateAccountSubscriptionDto
{
    /** @param  array<string, mixed>|null  $metadata */
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
