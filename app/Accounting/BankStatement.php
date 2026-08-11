<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * Canoniek bank- of kasafschrift.
 *
 * Bewust een afschrift met regels en niet een losse "banktransactie": zo modelleren de
 * pakketten het ook. De header draagt het dagboek, de periode en het open- en
 * sluitsaldo waarmee je kunt controleren of je alle regels hebt; de mutaties zelf
 * staan in {@see BankStatementLine}.
 *
 * `kind` onderscheidt bank van kas — twee bronnen bij de partner, één canonieke vorm.
 */
final readonly class BankStatement
{
    public const KIND_BANK = 'bank';

    public const KIND_CASH = 'cash';

    /**
     * @param  list<BankStatementLine>  $lines
     */
    public function __construct(
        public string $id,
        public string $kind,
        public array $lines,
        public ?string $number = null,
        public ?string $journal = null,
        public ?int $financialYear = null,
        public ?int $financialPeriod = null,
        public ?float $openingBalance = null,
        public ?float $closingBalance = null,
        public string $currency = 'EUR',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'number' => $this->number,
            'journal' => $this->journal,
            'financial_year' => $this->financialYear,
            'financial_period' => $this->financialPeriod,
            'opening_balance' => $this->openingBalance,
            'closing_balance' => $this->closingBalance,
            'currency' => $this->currency,
            'lines' => array_map(
                static fn (BankStatementLine $line): array => $line->toArray(),
                $this->lines,
            ),
        ];
    }
}
