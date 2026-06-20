<?php

namespace App\Books\Enums;

use Filament\Support\Contracts\HasLabel;

enum TransactionType: string implements HasLabel
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case Journal = 'journal';
    case Transfer = 'transfer';

    public function getLabel(): ?string
    {
        return $this->name;
    }

    public function isDeposit(): bool
    {
        return $this === self::Deposit;
    }

    public function isWithdrawal(): bool
    {
        return $this === self::Withdrawal;
    }

    public function isJournal(): bool
    {
        return $this === self::Journal;
    }

    public function isTransfer(): bool
    {
        return $this === self::Transfer;
    }

    public function isStandard(): bool
    {
        return in_array($this, [self::Deposit, self::Withdrawal], true);
    }
}
