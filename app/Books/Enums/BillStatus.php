<?php

namespace App\Books\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BillStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Received = 'received';
    case Paid = 'paid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Concept',
            self::Received => 'Ontvangen',
            self::Paid => 'Betaald',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Received => 'info',
            self::Paid => 'success',
        };
    }
}
