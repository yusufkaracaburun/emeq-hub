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
}
