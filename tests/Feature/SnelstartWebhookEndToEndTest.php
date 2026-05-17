<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Webhooks\ForwardSnelstartWebhookToConsumerJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\PassThroughCall;
use Emeq\SnelstartApi\Webhooks\SnelstartWebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * HUB-06 acceptance-gate. Bewijst alle 5 Success Criteria uit
 * ROADMAP §Phase 5c end-to-end via de volle stack (route → SDK-middleware →
 * controller → forward-job). Per-scenario coverage zit in plan 03 + 04;
 * deze suite bewijst dat de combinatie werkt zonder middleware-mocks.
 *
 * Mapping: test_sc_{1..5}_* ↔ ROADMAP SC-1..SC-5.
 */
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
        Bus::fake([ForwardSnelstartWebhookToConsumerJob::class]);

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

        $audit = PassThroughCall::query()->inbound()->sole();
        $this->assertSame('snelstart', $audit->provider);
        $this->assertSame('/webhooks/snelstart', $audit->path);
        $this->assertSame(200, $audit->status);
        $this->assertSame('evt-001', $audit->event_id);
        $this->assertSame($consumer->id, $audit->consumer_id);
        $this->assertSame($account->id, $audit->account_id);
        $this->assertSame($connection->id, $audit->connection_id);
        $this->assertNull($audit->upstream_error);
        $this->assertNotNull($audit->request_fingerprint);

        Bus::assertDispatched(
            ForwardSnelstartWebhookToConsumerJob::class,
            fn (ForwardSnelstartWebhookToConsumerJob $job): bool => $job->snelstartConnection->id === $connection->id
                && $job->eventId === 'evt-001'
                && ($job->payload['administratieId'] ?? null) === 'admin-uuid-1',
        );
    }

    public function test_sc_2_invalid_signature_returns_401_without_audit(): void
    {
        Bus::fake([ForwardSnelstartWebhookToConsumerJob::class]);

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
        $this->assertSame(0, PassThroughCall::query()->count());

        Bus::assertNothingDispatched();
    }

    public function test_sc_3_unknown_administratie_returns_200_with_null_tenant_audit(): void
    {
        Bus::fake([ForwardSnelstartWebhookToConsumerJob::class]);

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

        $audit = PassThroughCall::query()->inbound()->sole();
        $this->assertNull($audit->consumer_id);
        $this->assertNull($audit->account_id);
        $this->assertNull($audit->connection_id);
        $this->assertSame('unknown_administratie_id', $audit->upstream_error);

        Bus::assertNothingDispatched();
    }

    public function test_sc_4_idempotent_event_id_does_not_redispatch(): void
    {
        Bus::fake([ForwardSnelstartWebhookToConsumerJob::class]);

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

        $audits = PassThroughCall::query()->inbound()->orderBy('id')->get();
        $this->assertCount(2, $audits);

        $this->assertSame('evt-dup', $audits[0]->event_id);
        $this->assertNull($audits[0]->upstream_error);

        $this->assertNull($audits[1]->event_id, 'Duplicate-rij heeft event_id NULL om de (provider, event_id) unique-index niet te triggeren');
        $this->assertSame('duplicate_event', $audits[1]->upstream_error);

        Bus::assertDispatchedTimes(ForwardSnelstartWebhookToConsumerJob::class, 1);
    }

    public function test_sc_5_cross_consumer_isolation_routes_to_correct_consumer(): void
    {
        Bus::fake([ForwardSnelstartWebhookToConsumerJob::class]);

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

        $audit = PassThroughCall::query()->inbound()->sole();
        $this->assertSame($consumerA->id, $audit->consumer_id);
        $this->assertSame($accountA->id, $audit->account_id);
        $this->assertSame($connectionA->id, $audit->connection_id);
        $this->assertNotSame($consumerB->id, $audit->consumer_id);

        Bus::assertDispatched(
            ForwardSnelstartWebhookToConsumerJob::class,
            fn (ForwardSnelstartWebhookToConsumerJob $job): bool => $job->snelstartConnection->id === $connectionA->id
                && $job->snelstartConnection->account->consumer_id === $consumerA->id
                && $job->snelstartConnection->id !== $connectionB->id,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
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
