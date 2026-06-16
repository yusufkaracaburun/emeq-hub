<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * Regel op een FinancialDocument. BTW wordt als tarief (0/9/21) gedragen — geen
 * provider-VATCode; de adapter mapt tarief → de code van de gekoppelde admin.
 * `category` is een vrije categorie/grootboek-hint die de adapter naar een
 * GLAccount vertaalt.
 */
final readonly class FinancialDocumentLine
{
    public function __construct(
        public string $description,
        public float $quantity,
        public float $unitPrice,
        public float $taxRate,
        public ?string $category = null,
    ) {}

    public function netAmount(): float
    {
        return round($this->quantity * $this->unitPrice, 2);
    }

    public function taxAmount(): float
    {
        return round($this->netAmount() * $this->taxRate / 100, 2);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            description: (string) $data['description'],
            quantity: (float) $data['quantity'],
            unitPrice: (float) $data['unit_price'],
            taxRate: (float) $data['tax_rate'],
            category: isset($data['category']) ? (string) $data['category'] : null,
        );
    }
}
