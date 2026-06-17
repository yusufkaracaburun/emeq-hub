<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * Regel op een FinancialDocument. `amount` is het leidende netto-bedrag — de Hub
 * vertrouwt dat en rekent niet zelf (qty×price), zodat er geen afrondingsverschil
 * met de bron ontstaat; `quantity`/`unitPrice` zijn optioneel/informatief. BTW
 * wordt als tarief (0/9/21) gedragen — geen provider-VATCode; de adapter mapt tarief
 * → de code van de gekoppelde admin. `category` is een vrije grootboek-hint die de
 * adapter naar een GLAccount vertaalt.
 */
final readonly class FinancialDocumentLine
{
    public function __construct(
        public string $description,
        public float $amount,
        public float $taxRate,
        public ?float $quantity = null,
        public ?float $unitPrice = null,
        public ?string $category = null,
    ) {}

    public function netAmount(): float
    {
        return round($this->amount, 2);
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
            amount: (float) $data['amount'],
            taxRate: (float) $data['tax_rate'],
            quantity: isset($data['quantity']) ? (float) $data['quantity'] : null,
            unitPrice: isset($data['unit_price']) ? (float) $data['unit_price'] : null,
            category: isset($data['category']) ? (string) $data['category'] : null,
        );
    }
}
