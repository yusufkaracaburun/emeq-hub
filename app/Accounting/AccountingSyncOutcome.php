<?php

declare(strict_types=1);

namespace App\Accounting;

final readonly class AccountingSyncOutcome
{
    /**
     * @param  array<string, mixed>  $responseBody
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $httpStatus,
        public array $responseBody,
        public array $headers = [],
    ) {}
}
