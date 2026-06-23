<?php

declare(strict_types=1);

namespace App\Accounting\Enums;

/**
 * BTW-behandeling op een regel. Onderscheidt "21% gewoon" van "21% verlegd" — beide
 * dragen hetzelfde tarief maar mappen op een andere Exact-VATCode (standard 21→3,
 * reverse_charge 21→6). Default Standard zodat bestaande consumers ongewijzigd boeken.
 * Scope v1: standard + reverse_charge; intra-EU (goederen/diensten) volgt later.
 */
enum TaxTreatment: string
{
    case Standard = 'standard';
    case ReverseCharge = 'reverse_charge';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t): string => $t->value, self::cases());
    }

    /**
     * Composite-sleutel voor de `accounting_mapping.vat_codes`-map: standard leest de platte
     * tarief-sleutel (backward-compat), verlegd `behandeling:tarief`. Eén bron voor het
     * formaat dat de mapping-deriver schrijft en de reference-resolver leest.
     */
    public function vatCodeKey(string $rate): string
    {
        return $this === self::Standard ? $rate : $this->value.':'.$rate;
    }
}
