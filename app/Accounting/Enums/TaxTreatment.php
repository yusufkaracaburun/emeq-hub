<?php

declare(strict_types=1);

namespace App\Accounting\Enums;

enum TaxTreatment: string
{
    case Standard = 'standard';
    case ReverseCharge = 'reverse_charge';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t): string => $t->value, self::cases());
    }

    public function vatCodeKey(string $rate): string
    {
        return $this === self::Standard ? $rate : $this->value.':'.$rate;
    }
}
