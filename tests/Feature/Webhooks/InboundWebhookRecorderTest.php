<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Integrations\Webhooks\InboundWebhookRecorder;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\InboundWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Tests\TestCase;

/**
 * Unit-ish feature-test voor de single write-path achter alle inbound
 * partner→Hub-webhook-audit. Bewijst kolom-mapping, tenant-afleiding via
 * `$connection->account`, event_id-NULL-op-duplicate en de fingerprint.
 */
class InboundWebhookRecorderTest extends TestCase
{
    use RefreshDatabase;

    private InboundWebhookRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = new InboundWebhookRecorder;
    }

    private function jsonRequest(string $body = '{"id":"tr_test"}'): Request
    {
        return Request::create(
            uri: '/webhooks/test',
            method: 'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
    }

    public function test_records_metadata_columns_for_a_processed_event(): void
    {
        $event = $this->recorder->record(
            'snelstart',
            $this->jsonRequest(),
            200,
            InboundWebhookRecorder::OUTCOME_PROCESSED,
            eventId: 'evt-1',
            topic: 'Relatie.Created',
            action: 'Created',
            fanoutStatus: InboundWebhookRecorder::FANOUT_DISPATCHED,
        );

        $fresh = InboundWebhookEvent::query()->sole();
        $this->assertTrue($fresh->is($event));
        $this->assertSame('snelstart', $fresh->provider);
        $this->assertSame('evt-1', $fresh->event_id);
        $this->assertSame('Relatie.Created', $fresh->topic);
        $this->assertSame('Created', $fresh->action);
        $this->assertSame(200, $fresh->status);
        $this->assertSame('processed', $fresh->outcome);
        $this->assertSame('dispatched', $fresh->fanout_status);
        $this->assertNotNull($fresh->received_at);
    }

    public function test_derives_tenant_columns_from_connection_account(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forSnelstart()->active()->for($account)->create();

        $this->recorder->record(
            'snelstart',
            $this->jsonRequest(),
            200,
            InboundWebhookRecorder::OUTCOME_PROCESSED,
            connection: $connection,
        );

        $event = InboundWebhookEvent::query()->sole();
        $this->assertSame($connection->id, $event->connection_id);
        $this->assertSame($account->id, $event->account_id);
        $this->assertSame($consumer->id, $event->consumer_id);
    }

    public function test_tenant_columns_are_null_without_connection(): void
    {
        $this->recorder->record(
            'snelstart',
            $this->jsonRequest(),
            200,
            InboundWebhookRecorder::OUTCOME_UNKNOWN_TENANT,
            eventId: 'evt-unknown',
        );

        $event = InboundWebhookEvent::query()->sole();
        $this->assertNull($event->connection_id);
        $this->assertNull($event->account_id);
        $this->assertNull($event->consumer_id);
    }

    public function test_nulls_event_id_on_duplicate_outcome(): void
    {
        $this->recorder->record(
            'snelstart',
            $this->jsonRequest(),
            200,
            InboundWebhookRecorder::OUTCOME_DUPLICATE,
            eventId: 'evt-dup',
        );

        $event = InboundWebhookEvent::query()->sole();
        $this->assertNull($event->event_id, 'Duplicate-rij krijgt event_id NULL om de unique-index niet te triggeren');
        $this->assertSame('duplicate', $event->outcome);
    }

    public function test_computes_fingerprint_from_raw_body(): void
    {
        $body = '{"id":"tr_fingerprint"}';

        $this->recorder->record(
            'mollie',
            $this->jsonRequest($body),
            202,
            InboundWebhookRecorder::OUTCOME_PROCESSED,
        );

        $event = InboundWebhookEvent::query()->sole();
        $expected = mb_substr(hash('sha256', $body), 0, 12);
        $this->assertSame($expected, $event->request_fingerprint);
    }

    public function test_fingerprint_is_null_for_empty_body(): void
    {
        $this->recorder->record(
            'cashier',
            $this->jsonRequest(''),
            500,
            InboundWebhookRecorder::OUTCOME_MISCONFIGURED,
        );

        $event = InboundWebhookEvent::query()->sole();
        $this->assertNull($event->request_fingerprint);
    }

    public function test_is_duplicate_reflects_existing_provider_event_pair(): void
    {
        $this->assertFalse($this->recorder->isDuplicate('snelstart', 'evt-9'));

        $this->recorder->record(
            'snelstart',
            $this->jsonRequest(),
            200,
            InboundWebhookRecorder::OUTCOME_PROCESSED,
            eventId: 'evt-9',
        );

        $this->assertTrue($this->recorder->isDuplicate('snelstart', 'evt-9'));
        // Andere provider met dezelfde event_id is geen duplicaat.
        $this->assertFalse($this->recorder->isDuplicate('exact', 'evt-9'));
    }

    /**
     * De recorder kent het correlatie-id niet; de model-hook haalt 'm uit de
     * request-context. Deze test bewaakt dat die hook blijft vuren.
     */
    public function test_records_the_request_id_from_the_context(): void
    {
        Context::add('request_id', '01HRECORDER00000000000000');

        $this->recorder->record(
            'exact',
            $this->jsonRequest(),
            200,
            InboundWebhookRecorder::OUTCOME_PROCESSED,
            eventId: 'evt-ctx',
        );

        $this->assertSame(
            '01HRECORDER00000000000000',
            InboundWebhookEvent::query()->sole()->request_id
        );
    }

    public function test_request_id_stays_null_without_a_context(): void
    {
        $this->recorder->record(
            'exact',
            $this->jsonRequest(),
            200,
            InboundWebhookRecorder::OUTCOME_PROCESSED,
            eventId: 'evt-no-ctx',
        );

        $this->assertNull(InboundWebhookEvent::query()->sole()->request_id);
    }
}
