<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Webhooks;

use App\Integrations\Webhooks\ConsumerWebhookHeaders;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

class ConsumerWebhookHeadersTest extends TestCase
{
    public function test_includes_both_ids_when_available(): void
    {
        Context::add('request_id', '01HTESTREQ0000000000000000');

        $this->assertSame([
            'Accept' => 'application/json',
            'X-Emeq-Event-Id' => 'evt-1',
            'X-Emeq-Request-Id' => '01HTESTREQ0000000000000000',
        ], ConsumerWebhookHeaders::make('evt-1'));
    }

    public function test_omits_the_event_id_when_none_is_given(): void
    {
        Context::add('request_id', '01HTESTREQ0000000000000000');

        $this->assertSame(
            ['Accept' => 'application/json', 'X-Emeq-Request-Id' => '01HTESTREQ0000000000000000'],
            ConsumerWebhookHeaders::make()
        );
    }

    public function test_omits_the_request_id_outside_a_request_context(): void
    {
        $this->assertSame(
            ['Accept' => 'application/json', 'X-Emeq-Event-Id' => 'evt-2'],
            ConsumerWebhookHeaders::make('evt-2')
        );
    }

    public function test_always_asks_for_json(): void
    {
        $this->assertSame('application/json', ConsumerWebhookHeaders::make()['Accept']);
    }
}
