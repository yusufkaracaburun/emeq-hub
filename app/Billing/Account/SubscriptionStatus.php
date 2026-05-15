<?php

declare(strict_types=1);

namespace App\Billing\Account;

enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Paused = 'paused';
    case Canceled = 'canceled';
    case Completed = 'completed';
    case Unknown = 'unknown';
}
