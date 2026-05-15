<?php

declare(strict_types=1);

namespace App\Billing\Account\Exceptions;

use App\Billing\Account\SubscriptionStatus;
use RuntimeException;

final class InvalidStateTransitionException extends RuntimeException
{
    public function __construct(
        public readonly SubscriptionStatus $from,
        public readonly SubscriptionStatus $to,
    ) {
        parent::__construct(sprintf(
            'Ongeldige state-transition: %s → %s.',
            $from->value,
            $to->value,
        ));
    }

    public static function for(SubscriptionStatus $from, SubscriptionStatus $to): self
    {
        return new self($from, $to);
    }
}
