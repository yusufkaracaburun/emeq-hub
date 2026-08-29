<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Sentry\SuppressTestEvents;
use Sentry\Event;
use Sentry\State\HubInterface;
use Tests\TestCase;

class SentryConfigurationTest extends TestCase
{
    public function test_before_send_drops_events_captured_by_the_test_suite(): void
    {
        $result = SuppressTestEvents::handle(Event::createEvent(), null);

        $this->assertNull($result, 'Sentry moet geen events ontvangen tijdens de testsuite.');
    }

    public function test_the_sentry_client_is_actually_wired_with_the_suppression_callback(): void
    {
        $client = $this->app->make(HubInterface::class)->getClient();

        $this->assertNotNull($client, 'Geen Sentry-client gebouwd om op te testen.');

        $result = $client->getOptions()->getBeforeSendCallback()(Event::createEvent(), null);

        $this->assertNull($result, 'De live Sentry-client moet dezelfde onderdrukking gebruiken als SuppressTestEvents, niet een losstaande config-closure.');
    }
}
