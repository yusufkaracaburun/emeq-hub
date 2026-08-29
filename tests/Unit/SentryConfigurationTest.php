<?php

declare(strict_types=1);

namespace Tests\Unit;

use Sentry\Event;
use Tests\TestCase;

class SentryConfigurationTest extends TestCase
{
    public function test_before_send_drops_events_captured_by_the_test_suite(): void
    {
        $beforeSend = config('sentry.before_send');

        $result = $beforeSend(Event::createEvent(), null);

        $this->assertNull($result, 'Sentry moet geen events ontvangen tijdens de testsuite.');
    }
}
