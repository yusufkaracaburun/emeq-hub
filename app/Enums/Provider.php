<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Partner-providers waar de Hub mee koppelt. Single source of truth voor de
 * `connections.provider`-waarde, vervangt verspreide `'mollie'`/`'snelstart'`-literals.
 *
 * De backing-waarden komen 1:1 overeen met de keys in config/hub-providers.php;
 * ProviderCredentialDescriptor blijft die config als bron voor credential-metadata
 * lezen — deze enum dekt alleen de identiteit + presentatie.
 */
enum Provider: string implements HasColor, HasLabel
{
    case Mollie = 'mollie';
    case Snelstart = 'snelstart';
    case Exact = 'exact';

    /**
     * @return list<string>
     */
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
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Mollie => 'success',
            self::Snelstart => 'info',
            self::Exact => 'danger',
        };
    }
}
