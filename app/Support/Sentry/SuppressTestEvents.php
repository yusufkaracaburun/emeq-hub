<?php

declare(strict_types=1);

namespace App\Support\Sentry;

use Sentry\Event;
use Sentry\EventHint;

final class SuppressTestEvents
{
    public static function handle(Event $event, ?EventHint $hint): ?Event
    {
        return app()->runningUnitTests() ? null : $event;
    }
}
