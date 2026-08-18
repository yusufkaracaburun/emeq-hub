<?php

declare(strict_types=1);

namespace App\Accounting;

use Carbon\CarbonImmutable;

final readonly class BankStatementLine
{
    public function __construct(
        public string $id,
        public float $amount,
        public ?CarbonImmutable $date = null,
        public ?string $description = null,
        public ?string $relationId = null,
        public ?string $relationName = null,
        public ?string $ledgerAccountId = null,
        public ?string $ledgerAccountCode = null,
        public ?string $taxCode = null,
        public ?string $documentNumber = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date?->format('Y-m-d'),
            'amount' => $this->amount,
            'description' => $this->description,
            'relation' => [
                'id' => $this->relationId,
                'name' => $this->relationName,
            ],
            'ledger_account_id' => $this->ledgerAccountId,
            'ledger_account_code' => $this->ledgerAccountCode,
            'tax_code' => $this->taxCode,
            'document_number' => $this->documentNumber,
        ];
    }
}
