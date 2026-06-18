<?php

declare(strict_types=1);

namespace App\Billing\Account;

use Filament\Support\Contracts\HasColor;

enum SubscriptionStatus: string implements HasColor
{
    case Pending = 'pending';
    case Active = 'active';
    case Paused = 'paused';
    case Canceled = 'canceled';
    case Completed = 'completed';
    case Unknown = 'unknown';

    /**
     * Single source voor de badge-kleur van een subscription-status — gedeeld door
     * de AccountSubscription-resource en beide AccountSubscription-relation-managers.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Active => 'success',
            self::Paused => 'info',
            self::Canceled => 'danger',
            self::Completed, self::Unknown => 'gray',
        };
    }
}
