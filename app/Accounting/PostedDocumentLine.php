<?php

declare(strict_types=1);

namespace App\Accounting;

final readonly class PostedDocumentLine
{
    public function __construct(
        public float $amount,
        public ?string $description = null,
        public ?string $taxCode = null,
        public ?string $ledgerAccountId = null,
        public ?string $costCenter = null,
        public ?string $costUnit = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'amount' => $this->amount,
            'tax_code' => $this->taxCode,
            'ledger_account_id' => $this->ledgerAccountId,
            'cost_center' => $this->costCenter,
            'cost_unit' => $this->costUnit,
        ];
    }
}
