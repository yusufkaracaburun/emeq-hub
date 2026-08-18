<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Accounting;

use App\Accounting\AccountingSyncRunner;
use App\Accounting\FinancialDocument;
use App\Integrations\Webhooks\CanonicalEvent;
use App\Jobs\Accounting\SyncAccountingDocumentJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\ProviderEntityLink;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Http\Request\Write\CreateSalesEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Spatie\WebhookServer\CallWebhookJob;
use Tests\Concerns\BindsFakeAccountingReferences;
use Tests\TestCase;

class AsyncStoreDocumentTest extends TestCase
{
    use BindsFakeAccountingReferences;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.exact.client_id' => 'app_test_id',
            'services.exact.client_secret' => 'app_test_secret',
            'services.exact.redirect_uri' => 'https://hub.test/v1/oauth/exact/callback',
            'services.exact.auth_base_url' => 'https://start.exactonline.nl',
            'services.exact.api_base_url' => 'https://start.exactonline.nl',
        ]);
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $consumerState
     * @return array{0: Consumer, 1: Account, 2: Connection}
     */
    private function consumerWithExactConnection(array $consumerState = []): array
    {
        $consumer = empty($consumerState)
            ? Consumer::factory()->create()
            : Consumer::factory()->withWebhookCallback(...$consumerState)->create();

        $account = $consumer->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);

        $connection = Connection::factory()->forExact()->create([
            'account_id' => $account->id,
            'status' => 'active',
            'expires_at' => now()->addSeconds(600),
        ]);

        return [$consumer, $account, $connection];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function salesInvoicePayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'sales_invoice',
            'external_id' => 'INV-2026-001',
            'number' => '2026-001',
            'issue_date' => '2026-06-16',
            'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'kind' => 'company', 'external_id' => 'acme-1', 'vat_number' => 'NL000099998B57'],
            'lines' => [
                ['description' => 'Consultancy', 'amount' => 200, 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 21, 'category' => 'omzet'],
            ],
        ], $overrides);
    }

    public function test_async_request_returns_202_pending_and_queues_job(): void
    {
        Bus::fake([SyncAccountingDocumentJob::class]);
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection(['url' => 'https://consumer.test/cb', 'secret' => 's3cr3t']);
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->withHeader('Prefer', 'respond-async')
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('external_id', 'INV-2026-001');

        MockClient::global()->assertNothingSent();

        Bus::assertDispatched(
            SyncAccountingDocumentJob::class,
            fn (SyncAccountingDocumentJob $job): bool => $job->queue === 'webhooks'
                && $job->document->externalId === 'INV-2026-001'
        );
    }

    public function test_async_without_webhook_callback_returns_400(): void
    {
        Bus::fake([SyncAccountingDocumentJob::class]);

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->withHeader('Prefer', 'respond-async')
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(400)
            ->assertJson(['error' => 'webhook_required'])
            ->assertJsonPath('external_id', 'INV-2026-001');

        Bus::assertNotDispatched(SyncAccountingDocumentJob::class);
    }

    public function test_sync_is_default_without_prefer_header(): void
    {
        Bus::fake([SyncAccountingDocumentJob::class]);
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection(['url' => 'https://consumer.test/cb', 'secret' => 's3cr3t']);
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(201)
            ->assertJsonPath('status', 'posted');

        Bus::assertNotDispatched(SyncAccountingDocumentJob::class);
    }

    public function test_job_runs_push_and_fires_result_webhook(): void
    {
        Bus::fake([CallWebhookJob::class]);
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-9']], 201),
        ]);
        $this->bindFakeReferences();

        [, $account, $connection] = $this->consumerWithExactConnection([
            'url' => 'https://consumer.test/accounting',
            'secret' => 'consumer-secret-xyz',
        ]);
        $consumerId = (int) $account->consumer_id;
        $document = FinancialDocument::fromArray($this->salesInvoicePayload());

        (new SyncAccountingDocumentJob($document, $connection, $account, $consumerId))->handle(
            app(AccountingSyncRunner::class)
        );

        MockClient::global()->assertSent(CreateSalesEntry::class);

        Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job) use ($account): bool {
            $signatureHeader = config('webhook-server.signature_header_name', 'Signature');
            $expectedSignature = hash_hmac('sha256', json_encode($job->payload), 'consumer-secret-xyz');

            return $job->webhookUrl === 'https://consumer.test/accounting'
                && $job->payload['event'] === CanonicalEvent::DOCUMENT_SYNCED
                && $job->payload['provider'] === 'exact'
                && $job->payload['account_id'] === $account->external_id
                && is_string($job->payload['occurred_at'])
                && $job->payload['data']['status'] === 'posted'
                && $job->payload['data']['external_id'] === 'INV-2026-001'
                && $job->payload['data']['external_ref'] === 'inv-guid-9'
                && ($job->headers[$signatureHeader] ?? null) === $expectedSignature;
        });

        $this->assertDatabaseHas('pass_through_calls', [
            'direction' => 'outbound',
            'provider' => 'exact',
            'path' => '/v1/accounting/documents',
            'status' => 201,
        ]);
    }

    public function test_job_fires_failed_webhook_on_push_error(): void
    {
        Bus::fake([CallWebhookJob::class]);
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['error' => ['message' => ['value' => 'boom']]], 500),
        ]);
        $this->bindFakeReferences();

        [, $account, $connection] = $this->consumerWithExactConnection([
            'url' => 'https://consumer.test/accounting',
            'secret' => 'consumer-secret-xyz',
        ]);
        $consumerId = (int) $account->consumer_id;
        $document = FinancialDocument::fromArray($this->salesInvoicePayload());

        (new SyncAccountingDocumentJob($document, $connection, $account, $consumerId))->handle(
            app(AccountingSyncRunner::class)
        );

        Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job): bool {
            return $job->payload['event'] === CanonicalEvent::DOCUMENT_SYNCED
                && $job->payload['data']['status'] === 'failed'
                && $job->payload['data']['external_id'] === 'INV-2026-001';
        });
    }

    public function test_job_does_not_retry_to_avoid_double_booking(): void
    {
        [, $account, $connection] = $this->consumerWithExactConnection();
        $document = FinancialDocument::fromArray($this->salesInvoicePayload());

        $job = new SyncAccountingDocumentJob($document, $connection, $account, 1);

        $this->assertSame(1, $job->tries);
    }

    public function test_async_job_records_the_provider_entity_link(): void
    {
        Bus::fake([CallWebhookJob::class]);
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-async']], 201),
        ]);
        $this->bindFakeReferences();

        [, $account, $connection] = $this->consumerWithExactConnection([
            'url' => 'https://consumer.test/accounting',
            'secret' => 'consumer-secret-xyz',
        ]);
        $document = FinancialDocument::fromArray($this->salesInvoicePayload());

        (new SyncAccountingDocumentJob($document, $connection, $account, (int) $account->consumer_id))->handle(
            app(AccountingSyncRunner::class)
        );

        $this->assertDatabaseHas('provider_entity_links', [
            'connection_id' => $connection->id,
            'external_id' => 'INV-2026-001',
            'provider_entity_id' => 'inv-guid-async',
            'origin' => ProviderEntityLink::ORIGIN_HUB,
        ]);
    }

    public function test_async_job_deduplicates_a_repeat_of_the_same_document(): void
    {
        Bus::fake([CallWebhookJob::class]);
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-async2']], 201),
        ]);
        $this->bindFakeReferences();

        [, $account, $connection] = $this->consumerWithExactConnection([
            'url' => 'https://consumer.test/accounting',
            'secret' => 'consumer-secret-xyz',
        ]);
        $consumerId = (int) $account->consumer_id;
        $document = FinancialDocument::fromArray($this->salesInvoicePayload());
        $runner = app(AccountingSyncRunner::class);

        (new SyncAccountingDocumentJob($document, $connection, $account, $consumerId))->handle($runner);
        (new SyncAccountingDocumentJob($document, $connection, $account, $consumerId))->handle($runner);

        MockClient::global()->assertSentCount(1);
        $this->assertDatabaseCount('provider_entity_links', 1);

        Bus::assertDispatched(
            CallWebhookJob::class,
            fn (CallWebhookJob $job): bool => ($job->payload['data']['deduplicated'] ?? false) === true
                && $job->payload['data']['status'] === 'posted'
        );
    }
}
