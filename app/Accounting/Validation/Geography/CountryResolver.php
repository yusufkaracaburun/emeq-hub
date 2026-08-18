<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Geography;

final class CountryResolver
{
    public const HOME = 'NL';

    /** @var list<string> */
    private const EU = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'EL', 'GR',
        'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI',
        'ES', 'SE',
    ];

    public static function fromVatNumber(?string $vatNumber): ?string
    {
        return self::prefix($vatNumber);
    }

    public static function fromIban(?string $iban): ?string
    {
        return self::prefix($iban);
    }

    public static function region(?string $country): Region
    {
        if ($country === null) {
            return Region::Unknown;
        }

        $country = strtoupper($country);

        if ($country === self::HOME) {
            return Region::Domestic;
        }

        return in_array($country, self::EU, true) ? Region::Eu : Region::NonEu;
    }

    private static function prefix(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');

        if (! preg_match('/^[A-Z]{2}/', $normalized, $m)) {
            return null;
        }

        return $m[0] === 'EL' ? 'GR' : $m[0];
    }
}
