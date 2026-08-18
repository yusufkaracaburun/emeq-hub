<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Accounting\Enums\DocumentType;
use Carbon\CarbonImmutable;

final readonly class FinancialDocument
{
    /**
     * @param  list<FinancialDocumentLine>  $lines
     * @param  list<Attachment>  $attachments
     */
    public function __construct(
        public DocumentType $type,
        public string $externalId,
        public Party $party,
        public array $lines,
        public CarbonImmutable $issueDate,
        public ?CarbonImmutable $dueDate = null,
        public ?string $number = null,
        public ?string $reference = null,
        public string $currency = 'EUR',
        public bool $pricesIncludeTax = false,
        public array $attachments = [],
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $issueDate = CarbonImmutable::parse((string) $data['issue_date']);

        return new self(
            type: DocumentType::from((string) $data['type']),
            externalId: (string) $data['external_id'],
            party: Party::fromArray($data['party']),
            lines: array_values(array_map(
                fn (array $line): FinancialDocumentLine => FinancialDocumentLine::fromArray($line),
                $data['lines'],
            )),
            issueDate: $issueDate,
            dueDate: isset($data['due_date']) ? CarbonImmutable::parse((string) $data['due_date']) : $issueDate->addMonth(),
            number: isset($data['number']) ? (string) $data['number'] : null,
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            currency: isset($data['currency']) ? (string) $data['currency'] : 'EUR',
            pricesIncludeTax: (bool) ($data['prices_include_tax'] ?? false),
            attachments: array_values(array_map(
                fn (array $attachment): Attachment => Attachment::fromArray($attachment),
                $data['attachments'] ?? [],
            )),
        );
    }

    public function netTotal(): float
    {
        return round(array_sum(array_map(fn (FinancialDocumentLine $l): float => $l->netAmount(), $this->lines)), 2);
    }

    public function taxTotal(): float
    {
        return round(array_sum(array_map(fn (FinancialDocumentLine $l): float => $l->taxAmount(), $this->lines)), 2);
    }

    public function grossTotal(): float
    {
        return round($this->netTotal() + $this->taxTotal(), 2);
    }
}
