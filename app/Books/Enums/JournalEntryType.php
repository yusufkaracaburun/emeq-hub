<?php

namespace App\Books\Enums;

use Filament\Support\Contracts\HasLabel;

enum JournalEntryType: string implements HasLabel
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function getLabel(): ?string
    {
        return $this->name;
    }

    public function isDebit(): bool
    {
        return $this === self::Debit;
    }

    public function isCredit(): bool
    {
        return $this === self::Credit;
    }
}
