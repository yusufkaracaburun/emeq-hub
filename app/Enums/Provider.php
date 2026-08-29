<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Provider: string implements HasColor, HasLabel
{
    case Mollie = 'mollie';
    case Snelstart = 'snelstart';
    case Exact = 'exact';
    case DataForSeo = 'dataforseo';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $p): string => $p->value, self::cases());
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Mollie => 'Mollie',
            self::Snelstart => 'Snelstart',
            self::Exact => 'Exact Online',
            self::DataForSeo => 'DataForSEO',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Mollie => 'success',
            self::Snelstart => 'info',
            self::Exact => 'danger',
            self::DataForSeo => 'warning',
        };
    }
}
