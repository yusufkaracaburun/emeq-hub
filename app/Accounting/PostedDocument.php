<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Accounting\Enums\DocumentType;
use Carbon\CarbonImmutable;

final readonly class PostedDocument
{
    /** @param  list<PostedDocumentLine>  $lines */
    public function __construct(
        public string $id,
        public DocumentType $type,
        public array $lines,
        public ?string $number = null,
        public ?string $externalId = null,
        public ?CarbonImmutable $issueDate = null,
        public ?CarbonImmutable $dueDate = null,
        public ?string $reference = null,
        public ?string $partyId = null,
        public ?string $partyName = null,
        public ?string $journal = null,
        public string $currency = 'EUR',
    ) {}

    public function netTotal(): float
    {
        return round(array_sum(array_map(
            static fn (PostedDocumentLine $line): float => $line->amount,
            $this->lines,
        )), 2);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'number' => $this->number,
            'external_id' => $this->externalId,
            'issue_date' => $this->issueDate?->format('Y-m-d'),
            'due_date' => $this->dueDate?->format('Y-m-d'),
            'reference' => $this->reference,
            'party' => [
                'id' => $this->partyId,
                'name' => $this->partyName,
            ],
            'journal' => $this->journal,
            'currency' => $this->currency,
            'net_total' => $this->netTotal(),
            'lines' => array_map(
                static fn (PostedDocumentLine $line): array => $line->toArray(),
                $this->lines,
            ),
        ];
    }
}
