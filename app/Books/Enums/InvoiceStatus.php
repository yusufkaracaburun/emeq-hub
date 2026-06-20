<?php

namespace App\Books\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InvoiceStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Paid = 'paid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Concept',
            self::Sent => 'Verzonden',
            self::Paid => 'Betaald',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'info',
            self::Paid => 'success',
        };
    }
}
