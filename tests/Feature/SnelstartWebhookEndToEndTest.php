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

final class SnelstartWebhookEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER_SECRET = 'partner-secret-e2e';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'snelstart.webhook.secret' => self::PARTNER_SECRET,
            'snelstart.webhook.signature_header' => 'X-SnelStart-Signature',
            'snelstart.webhook.signature_algo' => 'sha256',
            'snelstart.webhook.event_id_key' => 'eventId',
        ]);
    }

    public function test_sc_1_valid_known_administratie_dispatches_forward_job(): void
    {
        Bus::fake([ForwardWebhookToConsumerJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()
            ->forSnelstart()
            ->active()
            ->for($account)
            ->create(['administratie_id' => 'admin-uuid-1']);

        $response = $this->postSignedWebhook([
            'administratieId' => 'admin-uuid-1',
            'eventId' => 'evt-001',
            'type' => 'Verkoopfactuur.Updated',
            'payload' => ['id' => 'inv-99'],
        ]);

        $response->assertStatus(200);

        $event = InboundWebhookEvent::query()->sole();
        $this->assertSame('snelstart', $event->provider);
        $this->assertSame(200, $event->status);
        $this->assertSame('processed', $event->outcome);
        $this->assertSame('dispatched', $event->fanout_status);
        $this->assertSame('evt-001', $event->event_id);
        $this->assertSame('Verkoopfactuur.Updated', $event->topic);
        $this->assertSame($consumer->id, $event->consumer_id);
        $this->assertSame($account->id, $event->account_id);
        $this->assertSame($connection->id, $event->connection_id);
        $this->assertNotNull($event->request_fingerprint);

        Bus::assertDispatched(
            ForwardWebhookToConsumerJob::class,
            fn (ForwardWebhookToConsumerJob $job): bool => $job->providerConnection->id === $connection->id
                && $job->eventId === 'evt-001'
                && ($job->payload['administratieId'] ?? null) === 'admin-uuid-1',
        );
    }

    public function test_sc_2_invalid_signature_returns_401_without_audit(): void
    {
        Bus::fake([ForwardWebhookToConsumerJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create();
        Connection::factory()
            ->forSnelstart()
            ->active()
            ->for($account)
            ->create(['administratie_id' => 'admin-uuid-1']);

        $payload = [
            'administratieId' => 'admin-uuid-1',
            'eventId' => 'evt-bad',
        ];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $forgedSignature = SnelstartWebhookSignature::sign($rawBody, 'wrong-secret');

        $response = $this->call(
            method: 'POST',
            uri: '/webhooks/snelstart',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SNELSTART_SIGNATURE' => $forgedSignature,
            ],
            content: $rawBody,
        );

        $response->assertStatus(401);
        $this->assertSame('', $response->getContent());
        $this->assertSame(0, InboundWebhookEvent::query()->count());

        Bus::assertNothingDispatched();
    }

    public function test_sc_3_unknown_administratie_returns_200_with_null_tenant_audit(): void
    {
        Bus::fake([ForwardWebhookToConsumerJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create();
        Connection::factory()
            ->forSnelstart()
            ->active()
            ->for($account)
            ->create(['administratie_id' => 'admin-uuid-1']);

        $response = $this->postSignedWebhook([
            'administratieId' => 'admin-uuid-zzz',
            'eventId' => 'evt-unknown',
        ]);

        $response->assertStatus(200);

        $event = InboundWebhookEvent::query()->sole();
        $this->assertNull($event->consumer_id);
        $this->assertNull($event->account_id);
        $this->assertNull($event->connection_id);
        $this->assertSame('unknown_tenant', $event->outcome);

        Bus::assertNothingDispatched();
    }

    public function test_sc_4_idempotent_event_id_does_not_redispatch(): void
    {
        Bus::fake([ForwardWebhookToConsumerJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create();
        Connection::factory()
            ->forSnelstart()
            ->active()
            ->for($account)
            ->create(['administratie_id' => 'admin-uuid-1']);

        $payload = [
            'administratieId' => 'admin-uuid-1',
            'eventId' => 'evt-dup',
            'type' => 'Relatie.Created',
        ];

        $this->postSignedWebhook($payload)->assertStatus(200);
        $this->postSignedWebhook($payload)->assertStatus(200);

        $events = InboundWebhookEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);

        $this->assertSame('evt-dup', $events[0]->event_id);
        $this->assertSame('processed', $events[0]->outcome);

        $this->assertNull($events[1]->event_id, 'Duplicate-rij heeft event_id NULL om de (provider, event_id) unique-index niet te triggeren');
        $this->assertSame('duplicate', $events[1]->outcome);
        $this->assertSame(200, $events[1]->status);

        Bus::assertDispatchedTimes(ForwardWebhookToConsumerJob::class, 1);
    }

    public function test_sc_5_cross_consumer_isolation_routes_to_correct_consumer(): void
    {
        Bus::fake([ForwardWebhookToConsumerJob::class]);

        $consumerA = Consumer::factory()->withWebhookCallback('https://a.example.test/hooks')->create();
        $accountA = Account::factory()->for($consumerA)->create();
        $connectionA = Connection::factory()
            ->forSnelstart()
            ->active()
            ->for($accountA)
            ->create(['administratie_id' => 'admin-A']);

        $consumerB = Consumer::factory()->withWebhookCallback('https://b.example.test/hooks')->create();
        $accountB = Account::factory()->for($consumerB)->create();
        $connectionB = Connection::factory()
            ->forSnelstart()
            ->active()
            ->for($accountB)
            ->create(['administratie_id' => 'admin-B']);

        $this->postSignedWebhook([
            'administratieId' => 'admin-A',
            'eventId' => 'evt-cross-A',
            'type' => 'Relatie.Created',
        ])->assertStatus(200);

        $event = InboundWebhookEvent::query()->sole();
        $this->assertSame($consumerA->id, $event->consumer_id);
        $this->assertSame($accountA->id, $event->account_id);
        $this->assertSame($connectionA->id, $event->connection_id);
        $this->assertNotSame($consumerB->id, $event->consumer_id);

        Bus::assertDispatched(
            ForwardWebhookToConsumerJob::class,
            fn (ForwardWebhookToConsumerJob $job): bool => $job->providerConnection->id === $connectionA->id
                && $job->providerConnection->account->consumer_id === $consumerA->id
                && $job->providerConnection->id !== $connectionB->id,
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
