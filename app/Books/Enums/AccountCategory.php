<?php

namespace App\Books\Enums;

use Filament\Support\Contracts\HasLabel;

enum AccountCategory: string implements HasLabel
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    public function getLabel(): ?string
    {
        return $this->name;
    }

    /**
     * Assets en expenses hebben normaal een debetsaldo (debet verhoogt, credit verlaagt).
     */
    public function isNormalDebitBalance(): bool
    {
        return in_array($this, [self::Asset, self::Expense], true);
    }

    public function isNormalCreditBalance(): bool
    {
        return ! $this->isNormalDebitBalance();
    }

    /**
     * Nominale rekeningen (revenue/expense) worden per periode afgesloten naar het
     * eigen vermogen; reële rekeningen (asset/liability/equity) dragen hun saldo door.
     */
    public function isNominal(): bool
    {
        return in_array($this, [self::Revenue, self::Expense], true);
    }

    public function isReal(): bool
    {
        return ! $this->isNominal();
    }

    /**
     * @return list<self>
     */
    public static function getOrderedCategories(): array
    {
        return [
            self::Asset,
            self::Liability,
            self::Equity,
            self::Revenue,
            self::Expense,
        ];
    }
}
