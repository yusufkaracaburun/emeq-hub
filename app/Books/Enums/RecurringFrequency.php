<?php

namespace App\Books\Enums;

use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

enum RecurringFrequency: string implements HasLabel
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function getLabel(): string
    {
        return match ($this) {
            self::Weekly => 'Wekelijks',
            self::Monthly => 'Maandelijks',
            self::Yearly => 'Jaarlijks',
        };
    }

    /**
     * De volgende boekdatum, gerekend vanaf $from. addMonthNoOverflow voorkomt
     * dat 31 jan → 3 mrt schuift bij maand-cadans.
     */
    public function nextDate(CarbonInterface $from): CarbonInterface
    {
        return match ($this) {
            self::Weekly => $from->copy()->addWeek(),
            self::Monthly => $from->copy()->addMonthNoOverflow(),
            self::Yearly => $from->copy()->addYear(),
        };
    }
}
