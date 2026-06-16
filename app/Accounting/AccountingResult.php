<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * Uitkomst van een AccountingTarget::push — de partner-status, de externe
 * referentie (bv. Exact-GUID) en de ruwe respons voor audit/debug.
 */
final readonly class AccountingResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public int $status,
        public ?string $externalRef,
        public array $raw = [],
    ) {}
}
