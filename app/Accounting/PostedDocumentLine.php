<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * Regel van een {@see PostedDocument}.
 *
 * `ledgerAccountId` en `taxCode` dragen de identiteiten zoals de partner ze kent —
 * dezelfde waarden die je via `/v1/accounting/{ledger-accounts,tax-codes}` terugvindt.
 * Bewust geen `category`: die vrije grootboek-hint bestaat alleen aan de schrijfzijde
 * en is bij het boeken al vertaald naar een rekening.
 */
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

    /**
     * @return array<string, mixed>
     */
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
