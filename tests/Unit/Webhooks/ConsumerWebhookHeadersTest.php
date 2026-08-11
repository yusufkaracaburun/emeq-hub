<?php

declare(strict_types=1);

namespace Tests\Unit\Webhooks;

use App\Integrations\Webhooks\ConsumerWebhookHeaders;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

class ConsumerWebhookHeadersTest extends TestCase
{
    public function test_includes_both_ids_when_available(): void
    {
        Context::add('request_id', '01HTESTREQ0000000000000000');

        $this->assertSame([
            'X-Emeq-Event-Id' => 'evt-1',
            'X-Emeq-Request-Id' => '01HTESTREQ0000000000000000',
        ], ConsumerWebhookHeaders::make('evt-1'));
    }

    /**
     * Mollie's fan-out heeft geen event-id; die header hoort dan te ontbreken in
     * plaats van leeg mee te gaan.
     */
    public function test_omits_the_event_id_when_none_is_given(): void
    {
        Context::add('request_id', '01HTESTREQ0000000000000000');

        $this->assertSame(
            ['X-Emeq-Request-Id' => '01HTESTREQ0000000000000000'],
            ConsumerWebhookHeaders::make()
        );
    }

    /**
     * Een fan-out vanuit een scheduled command heeft geen request-context. Dan
     * gaat er geen lege header mee.
     */
    public function test_omits_the_request_id_outside_a_request_context(): void
    {
        $this->assertSame(
            ['X-Emeq-Event-Id' => 'evt-2'],
            ConsumerWebhookHeaders::make('evt-2')
        );
    }
}
