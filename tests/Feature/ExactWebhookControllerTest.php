<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Webhooks\ForwardExactWebhookToConsumerJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\PassThroughCall;
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

        $audit = PassThroughCall::query()->inbound()->sole();
        $this->assertSame('exact', $audit->provider);
        $this->assertSame('/webhooks/exact', $audit->path);
        $this->assertSame(200, $audit->status);
        $this->assertNotNull($audit->event_id);
        $this->assertSame($consumer->id, $audit->consumer_id);
        $this->assertSame($account->id, $audit->account_id);
        $this->assertSame($connection->id, $audit->connection_id);
        $this->assertNull($audit->upstream_error);
        $this->assertNotNull($audit->request_fingerprint);

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
        $this->assertSame(0, PassThroughCall::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_unknown_division_returns_200_with_null_tenant_audit(): void
    {
        Bus::fake([ForwardExactWebhookToConsumerJob::class]);

        $response = $this->postSignedWebhook($this->content(division: 999999));

        $response->assertStatus(200);

        $audit = PassThroughCall::query()->inbound()->sole();
        $this->assertNull($audit->consumer_id);
        $this->assertNull($audit->account_id);
        $this->assertNull($audit->connection_id);
        $this->assertSame('unknown_division', $audit->upstream_error);
        $this->assertNotNull($audit->event_id);

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

        $audits = PassThroughCall::query()->inbound()->orderBy('id')->get();
        $this->assertCount(2, $audits);

        $this->assertNotNull($audits[0]->event_id);
        $this->assertNull($audits[0]->upstream_error);

        $this->assertNull($audits[1]->event_id, 'Duplicate-rij heeft event_id NULL om de unique-index niet te triggeren');
        $this->assertSame('duplicate_event', $audits[1]->upstream_error);

        Bus::assertDispatchedTimes(ForwardExactWebhookToConsumerJob::class, 1);
    }

    public function test_content_without_division_returns_400_with_audit(): void
    {
        Bus::fake([ForwardExactWebhookToConsumerJob::class]);

        $response = $this->postSignedWebhook(['Topic' => 'FinancialTransactions', 'Action' => 'UPDATE']);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'malformed_payload']);

        $audit = PassThroughCall::query()->inbound()->sole();
        $this->assertSame(400, $audit->status);
        $this->assertSame('malformed_payload', $audit->upstream_error);
        $this->assertNull($audit->consumer_id);

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
        $this->assertSame(0, PassThroughCall::query()->count());
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

        $audit = PassThroughCall::query()->inbound()->sole();
        $this->assertNull($audit->consumer_id);
        $this->assertNull($audit->connection_id);
        $this->assertSame('unknown_division', $audit->upstream_error);

        Bus::assertNothingDispatched();
    }

    /**
     * @return array<string, mixed>
     */
    private function content(int|string $division = self::DIVISION): array
    {
        return [
            'Topic' => 'FinancialTransactions',
            'Action' => 'UPDATE',
            'Division' => (int) $division,
            'Key' => '11111111-2222-3333-4444-555555555555',
            'ExactOnlineEndpoint' => "/api/v1/{$division}/financialtransaction/Transactions",
            'EventCreatedOn' => '/Date(1781791000000)/',
        ];
    }

    /**
     * Bouwt een Exact-notificatie waarvan de HashCode over de letterlijke
     * Content-substring is berekend (zoals Exact zelf doet).
     *
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
