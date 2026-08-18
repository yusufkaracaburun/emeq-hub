<?php

declare(strict_types=1);

namespace App\Filament\Books\Concerns;

trait GatedToBoekhouding
{
    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super-admin', 'boekhouder']) ?? false;
    }
}
