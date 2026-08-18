<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Webhooks\ForwardWebhookToConsumerJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\InboundWebhookEvent;
use Emeq\SnelstartApi\Webhooks\SnelstartWebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SnelstartWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER_SECRET = 'partner-secret-test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['snelstart.webhook.secret' => self::PARTNER_SECRET]);
        config(['snelstart.webhook.signature_header' => 'X-SnelStart-Signature']);
        config(['snelstart.webhook.signature_algo' => 'sha256']);
        config(['snelstart.webhook.event_id_key' => 'eventId']);
    }

    public function test_valid_webhook_with_known_administratie_dispatches_job(): void
    {
        Bus::fake([ForwardWebhookToConsumerJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()
            ->forSnelstart()
            ->active()
            ->for($account)
            ->create(['administratie_id' => 'aaa-111']);

        $response = $this->postSignedWebhook([
            'administratieId' => 'aaa-111',
            'eventId' => 'evt-1',
            'type' => 'Relatie.Created',
        ]);

        $response->assertStatus(200);
        $response->assertNoContent(200);

        $events = InboundWebhookEvent::query()->get();
        $this->assertCount(1, $events);

        $event = $events->first();
        $this->assertSame('snelstart', $event->provider);
        $this->assertSame(200, $event->status);
        $this->assertSame('processed', $event->outcome);
        $this->assertSame('dispatched', $event->fanout_status);
        $this->assertSame('evt-1', $event->event_id);
        $this->assertSame('Relatie.Created', $event->topic);
        $this->assertSame($consumer->id, $event->consumer_id);
        $this->assertSame($account->id, $event->account_id);
        $this->assertSame($connection->id, $event->connection_id);
        $this->assertNotNull($event->request_fingerprint);

        Bus::assertDispatched(
            ForwardWebhookToConsumerJob::class,
            function (ForwardWebhookToConsumerJob $job) use ($connection): bool {
                return $job->providerConnection->is($connection)
                    && $job->eventId === 'evt-1'
                    && $job->payload['administratieId'] === 'aaa-111';
            },
        );
    }

    public function test_unknown_administratie_returns_200_with_null_tenant_audit(): void
    {
        Bus::fake([ForwardWebhookToConsumerJob::class]);

        $response = $this->postSignedWebhook([
            'administratieId' => 'bbb-222',
            'eventId' => 'evt-unknown',
        ]);

        $response->assertStatus(200);

        $event = InboundWebhookEvent::query()->sole();
        $this->assertNull($event->consumer_id);
        $this->assertNull($event->account_id);
        $this->assertNull($event->connection_id);
        $this->assertSame('unknown_tenant', $event->outcome);
        $this->assertSame('evt-unknown', $event->event_id);

        Bus::assertNothingDispatched();
    }

    public function test_idempotent_duplicate_event_id_does_not_redispatch(): void
    {
        Bus::fake([ForwardWebhookToConsumerJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create();
        Connection::factory()
            ->forSnelstart()
            ->active()
            ->for($account)
            ->create(['administratie_id' => 'aaa-111']);

        $payload = [
            'administratieId' => 'aaa-111',
            'eventId' => 'evt-duplicate',
            'type' => 'Relatie.Created',
        ];

        $this->postSignedWebhook($payload)->assertStatus(200);
        $this->postSignedWebhook($payload)->assertStatus(200);

        $events = InboundWebhookEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);

        $this->assertSame('evt-duplicate', $events[0]->event_id);
        $this->assertSame('processed', $events[0]->outcome);

        $this->assertNull($events[1]->event_id, 'Duplicate-rij heeft event_id NULL om unique-index niet te triggeren');
        $this->assertSame('duplicate', $events[1]->outcome);
        $this->assertSame(200, $events[1]->status);

        Bus::assertDispatchedTimes(ForwardWebhookToConsumerJob::class, 1);
    }

    public function test_malformed_payload_returns_400_with_audit(): void
    {
        Bus::fake([ForwardWebhookToConsumerJob::class]);

        $response = $this->postSignedWebhook([
            'eventId' => 'evt-no-administratie',
            'type' => 'Relatie.Created',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'malformed_payload']);

        $event = InboundWebhookEvent::query()->sole();
        $this->assertSame(400, $event->status);
        $this->assertSame('malformed', $event->outcome);
        $this->assertNull($event->consumer_id);

        Bus::assertNothingDispatched();
    }

    public function test_invalid_signature_returns_401_without_audit(): void
    {
        Bus::fake([ForwardWebhookToConsumerJob::class]);

        $response = $this->postJson(
            '/webhooks/snelstart',
            ['administratieId' => 'aaa-111'],
            ['X-SnelStart-Signature' => 'not-a-valid-hmac'],
        );

        $response->assertStatus(401);

        $this->assertSame(0, InboundWebhookEvent::query()->count());

        Bus::assertNothingDispatched();
    }

    public function test_revoked_connection_treated_as_unknown(): void
    {
        Bus::fake([ForwardWebhookToConsumerJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create();
        Connection::factory()
            ->forSnelstart()
            ->active()
            ->for($account)
            ->create([
                'administratie_id' => 'aaa-111',
                'revoked_at' => now(),
            ]);

        $response = $this->postSignedWebhook([
            'administratieId' => 'aaa-111',
            'eventId' => 'evt-revoked',
        ]);

        $response->assertStatus(200);

        $event = InboundWebhookEvent::query()->sole();
        $this->assertNull($event->consumer_id);
        $this->assertNull($event->connection_id);
        $this->assertSame('unknown_tenant', $event->outcome);

        Bus::assertNothingDispatched();
    }

    public function test_cross_consumer_isolation_routes_to_correct_consumer(): void
    {
        Bus::fake([ForwardWebhookToConsumerJob::class]);

        $consumerA = Consumer::factory()->withWebhookCallback()->create();
        $accountA = Account::factory()->for($consumerA)->create();
        $connectionA = Connection::factory()
            ->forSnelstart()
            ->active()
            ->for($accountA)
            ->create(['administratie_id' => 'admin-A']);

        $consumerB = Consumer::factory()->withWebhookCallback()->create();
        $accountB = Account::factory()->for($consumerB)->create();
        Connection::factory()
            ->forSnelstart()
            ->active()
            ->for($accountB)
            ->create(['administratie_id' => 'admin-B']);

        $this->postSignedWebhook([
            'administratieId' => 'admin-A',
            'eventId' => 'evt-cross-A',
        ])->assertStatus(200);

        $event = InboundWebhookEvent::query()->sole();
        $this->assertSame($consumerA->id, $event->consumer_id);
        $this->assertSame($accountA->id, $event->account_id);
        $this->assertSame($connectionA->id, $event->connection_id);

        Bus::assertDispatched(
            ForwardWebhookToConsumerJob::class,
            function (ForwardWebhookToConsumerJob $job) use ($accountA): bool {
                return $job->providerConnection->account_id === $accountA->id;
            },
        );
    }

    /** @param  array<string, mixed>  $payload */
    private function postSignedWebhook(array $payload): TestResponse
    {
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = SnelstartWebhookSignature::sign($rawBody, self::PARTNER_SECRET);

        return $this->call(
            method: 'POST',
            uri: '/webhooks/snelstart',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SNELSTART_SIGNATURE' => $signature,
            ],
            content: $rawBody,
        );
    }
}
