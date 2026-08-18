<?php

declare(strict_types=1);

namespace App\Accounting;

final readonly class AccountingResult
{
    /**
     * @param  array<string, mixed>  $raw
     * @param  list<array{filename: string, status: string, document_ref: ?string, error: ?string}>  $attachments
     */
    public function __construct(
        public int $status,
        public ?string $externalRef,
        public ?int $externalNumber = null,
        public array $raw = [],
        public array $attachments = [],
    ) {}
}
