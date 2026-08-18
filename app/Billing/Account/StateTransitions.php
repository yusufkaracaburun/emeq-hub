<?php

declare(strict_types=1);

namespace App\Billing\Account;

use App\Billing\Account\Exceptions\InvalidStateTransitionException;

final class StateTransitions
{
    /** @return array<int, array{0: SubscriptionStatus, 1: SubscriptionStatus}> */
    private static function legalPairs(): array
    {
        return [
            [SubscriptionStatus::Pending, SubscriptionStatus::Active],
            [SubscriptionStatus::Pending, SubscriptionStatus::Canceled],
            [SubscriptionStatus::Active, SubscriptionStatus::Paused],
            [SubscriptionStatus::Active, SubscriptionStatus::Canceled],
            [SubscriptionStatus::Active, SubscriptionStatus::Completed],
            [SubscriptionStatus::Active, SubscriptionStatus::Unknown],
            [SubscriptionStatus::Paused, SubscriptionStatus::Active],
            [SubscriptionStatus::Paused, SubscriptionStatus::Canceled],
            [SubscriptionStatus::Paused, SubscriptionStatus::Unknown],
        ];
    }

    public static function assertTransition(SubscriptionStatus $from, SubscriptionStatus $to): void
    {
        if ($from === $to) {
            return;
        }

        if (! self::isLegal($from, $to)) {
            throw InvalidStateTransitionException::for($from, $to);
        }
    }

    public static function isLegal(SubscriptionStatus $from, SubscriptionStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        foreach (self::legalPairs() as [$legalFrom, $legalTo]) {
            if ($from === $legalFrom && $to === $legalTo) {
                return true;
            }
        }

        return false;
    }
}
