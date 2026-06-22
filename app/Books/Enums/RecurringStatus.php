<?php

namespace App\Books\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RecurringStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Actief',
            self::Paused => 'Gepauzeerd',
            self::Ended => 'Beëindigd',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Paused => 'warning',
            self::Ended => 'gray',
        };
    }
}
