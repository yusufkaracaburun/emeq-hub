<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Webhooks\ForwardExactWebhookToConsumerJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\InboundWebhookEvent;
use Emeq\ExactApi\Webhooks\ExactWebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ExactWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'exact-app-webhook-secret';

    private const DIVISION = '4471372';

    protected function setUp(): void
    {
        parent::setUp();

        config(['exact.webhook.secret' => self::APP_SECRET]);
        config(['exact.webhook.signature_algo' => 'sha256']);
    }

    public function test_valid_notification_with_known_division_dispatches_job(): void
    {
        Bus::fake([ForwardExactWebhookToConsumerJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()
            ->forExact()
            ->active()
            ->for($account)
            ->create(['administratie_id' => self::DIVISION]);

        $response = $this->postSignedWebhook($this->content());

        $response->assertStatus(200);
        $response->assertNoContent(200);

        $event = InboundWebhookEvent::query()->sole();
        $this->assertSame('exact', $event->provider);
        $this->assertSame('GeneralJournalEntries', $event->topic);
        $this->assertSame('Update', $event->action);
        $this->assertSame('processed', $event->outcome);
        $this->assertSame(200, $event->status);
        $this->assertSame('dispatched', $event->fanout_status);
        $this->assertNotNull($event->event_id);
        $this->assertSame($consumer->id, $event->consumer_id);
        $this->assertSame($account->id, $event->account_id);
        $this->assertSame($connection->id, $event->connection_id);
        $this->assertNotNull($event->request_fingerprint);

        Bus::assertDispatched(
            ForwardExactWebhookToConsumerJob::class,
            fn (ForwardExactWebhookToConsumerJob $job): bool => $job->exactConnection->is($connection)
                && $job->payload['Content']['Division'] === (int) self::DIVISION,
        );
    }

    public function test_empty_body_validation_ping_returns_200_without_audit(): void
    {
        Bus::fake([ForwardExactWebhookToConsumerJob::class]);

        $response = $this->call(
            method: 'POST',
            uri: '/webhooks/exact',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '',
        );

        $response->assertStatus(200);
        $this->assertSame(0, InboundWebhookEvent::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_unknown_division_returns_200_with_null_tenant_audit(): void
    {
        Bus::fake([ForwardExactWebhookToConsumerJob::class]);

        $response = $this->postSignedWebhook($this->content(division: 999999));

        $response->assertStatus(200);

        $event = InboundWebhookEvent::query()->sole();
        $this->assertNull($event->consumer_id);
        $this->assertNull($event->account_id);
        $this->assertNull($event->connection_id);
        $this->assertSame('unknown_tenant', $event->outcome);
        $this->assertNotNull($event->event_id);

        Bus::assertNothingDispatched();
    }

    public function test_idempotent_duplicate_notification_does_not_redispatch(): void
    {
        Bus::fake([ForwardExactWebhookToConsumerJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create();
        Connection::factory()
            ->forExact()
            ->active()
            ->for($account)
            ->create(['administratie_id' => self::DIVISION]);

        $content = $this->content();

        $this->postSignedWebhook($content)->assertStatus(200);
        $this->postSignedWebhook($content)->assertStatus(200);

        $events = InboundWebhookEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);

        $this->assertSame('processed', $events[0]->outcome);
        $this->assertNotNull($events[0]->event_id);

        $this->assertSame('duplicate', $events[1]->outcome);
        $this->assertNull($events[1]->event_id, 'Duplicate-rij heeft event_id NULL om de unique-index niet te triggeren');

        Bus::assertDispatchedTimes(ForwardExactWebhookToConsumerJob::class, 1);
    }

    public function test_content_without_division_returns_400_with_audit(): void
    {
        Bus::fake([ForwardExactWebhookToConsumerJob::class]);

        $response = $this->postSignedWebhook(['Topic' => 'GeneralJournalEntries', 'Action' => 'Update']);

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
        Bus::fake([ForwardExactWebhookToConsumerJob::class]);

        $contentJson = json_encode($this->content(), JSON_THROW_ON_ERROR);
        $body = '{"Content":'.$contentJson.',"HashCode":"not-a-valid-hashcode"}';

        $response = $this->call(
            method: 'POST',
            uri: '/webhooks/exact',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        $response->assertStatus(401);
        $this->assertSame(0, InboundWebhookEvent::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_revoked_connection_treated_as_unknown(): void
    {
        Bus::fake([ForwardExactWebhookToConsumerJob::class]);

        $consumer = Consumer::factory()->withWebhookCallback()->create();
        $account = Account::factory()->for($consumer)->create();
        Connection::factory()
            ->forExact()
            ->active()
            ->for($account)
            ->create([
                'administratie_id' => self::DIVISION,
                'revoked_at' => now(),
            ]);

        $response = $this->postSignedWebhook($this->content());

        $response->assertStatus(200);

        $event = InboundWebhookEvent::query()->sole();
        $this->assertNull($event->consumer_id);
        $this->assertNull($event->connection_id);
        $this->assertSame('unknown_tenant', $event->outcome);

        Bus::assertNothingDispatched();
    }

    /**
     * @return array<string, mixed>
     */
    private function content(int|string $division = self::DIVISION): array
    {
        return [
            'Topic' => 'GeneralJournalEntries',
            'Action' => 'Update',
            'Division' => (int) $division,
            'Key' => '11111111-2222-3333-4444-555555555555',
            'ExactOnlineEndpoint' => "/api/v1/{$division}/generaljournalentry/GeneralJournalEntries",
            'EventCreatedOn' => '/Date(1781791000000)/',
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function postSignedWebhook(array $content): TestResponse
    {
        $contentJson = json_encode($content, JSON_THROW_ON_ERROR);
        $hashCode = ExactWebhookSignature::sign($contentJson, self::APP_SECRET);
        $body = '{"Content":'.$contentJson.',"HashCode":'.json_encode($hashCode, JSON_THROW_ON_ERROR).'}';

        return $this->call(
            method: 'POST',
            uri: '/webhooks/exact',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
    }
}
