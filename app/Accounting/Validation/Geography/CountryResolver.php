<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Geography;

/**
 * Leidt het land af uit de landprefix van een BTW-nummer of IBAN (eerste twee letters,
 * ISO 3166-1 alpha-2) en classificeert het als binnenland (NL) / intra-EU / niet-EU.
 * Pure statische helper — gedeeld door de GeographyClassifier en VatTreatmentValidator.
 */
final class CountryResolver
{
    /** Thuisland van de Hub. */
    public const HOME = 'NL';

    /**
     * EU-lidstaten (ISO 3166-1 alpha-2). `EL` is de BTW-prefix die Griekenland gebruikt
     * i.p.v. `GR` — beide opgenomen zodat een BTW-prefix correct matcht.
     *
     * @var list<string>
     */
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

    /**
     * Eerste twee letters van een genormaliseerde code, of null als die er niet zijn.
     */
    private static function prefix(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');

        if (! preg_match('/^[A-Z]{2}/', $normalized, $m)) {
            return null;
        }

        // `EL` (Griekse BTW-prefix) → `GR` als ISO-landcode.
        return $m[0] === 'EL' ? 'GR' : $m[0];
    }
}
