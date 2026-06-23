<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Accounting\Enums\TaxTreatment;

/**
 * Regel op een FinancialDocument. `amount` is het leidende netto-bedrag — de Hub
 * vertrouwt dat en rekent niet zelf (qty×price), zodat er geen afrondingsverschil
 * met de bron ontstaat; `quantity`/`unitPrice` zijn optioneel/informatief. BTW
 * wordt als tarief (0/9/21) + behandeling gedragen — geen provider-VATCode; de adapter
 * mapt (behandeling, tarief) → de code van de gekoppelde admin, zodat "21% verlegd"
 * en "21% gewoon" naar verschillende VATCodes gaan. `category` is een vrije
 * grootboek-hint die de adapter naar een GLAccount vertaalt. `costCenter`/`costUnit`
 * zijn optionele kostenplaats-/kostendrager-Codes (provider-stabiel) die de adapter
 * ongewijzigd op de boekingsregel zet — geen mapping-laag.
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
        public ?string $costCenter = null,
        public ?string $costUnit = null,
        public TaxTreatment $taxTreatment = TaxTreatment::Standard,
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
            costCenter: isset($data['cost_center']) ? (string) $data['cost_center'] : null,
            costUnit: isset($data['cost_unit']) ? (string) $data['cost_unit'] : null,
            taxTreatment: TaxTreatment::tryFrom((string) ($data['tax_treatment'] ?? '')) ?? TaxTreatment::Standard,
        );
    }
}
