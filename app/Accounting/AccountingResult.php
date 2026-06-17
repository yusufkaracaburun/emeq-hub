<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * Uitkomst van een AccountingTarget::push — de partner-status, de externe
 * referentie (bv. Exact-GUID), de ruwe respons voor audit/debug, en per-bijlage
 * het upload-resultaat (best-effort, los van de leidende boeking).
 */
final readonly class AccountingResult
{
    /**
     * @param  array<string, mixed>  $raw
     * @param  list<array{filename: string, status: string, document_ref: ?string, error: ?string}>  $attachments
     */
    public function __construct(
        public int $status,
        public ?string $externalRef,
        public array $raw = [],
        public array $attachments = [],
    ) {}
}
