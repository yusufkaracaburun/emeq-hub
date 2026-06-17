<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Accounting;

use App\Accounting\AccountingSyncRunner;
use App\Accounting\Enums\DocumentType;
use App\Accounting\Exact\Contracts\ExactReferenceResolver;
use App\Accounting\FinancialDocument;
use App\Accounting\Party;
use App\Jobs\Accounting\SyncAccountingDocumentJob;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Http\Request\Write\CreateSalesEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Spatie\WebhookServer\CallWebhookJob;
use Tests\TestCase;

/**
 * Async-variant van de accounting-sync: `Prefer: respond-async` → 202 + pending,
 * de Exact-push draait in een queue-job die het resultaat per webhook terugmeldt.
 */
class AsyncStoreDocumentTest extends TestCase
{
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

    private function bindFakeReferences(): void
    {
        $this->app->bind(ExactReferenceResolver::class, fn (): ExactReferenceResolver => new class implements ExactReferenceResolver
        {
            public function relationGuid(Party $party, Connection $connection): string
            {
                return $party->role === 'creditor' ? 'supp-guid' : 'cust-guid';
            }

            public function vatCode(float $taxRate, Connection $connection): string
            {
                return '4';
            }

            public function glAccountGuid(?string $category, Connection $connection): ?string
            {
                return 'gl-guid';
            }

            public function journal(DocumentType $type, Connection $connection): string
            {
                return '90';
            }
        });
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
            'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'vat_number' => 'NL000099998B57'],
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

        // Push is uitgesteld naar de job — synchroon ging er niks naar Exact.
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

        [$consumer] = $this->consumerWithExactConnection(); // geen webhook_callback_url
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

        // Push is daadwerkelijk gedaan vanuit de job.
        MockClient::global()->assertSent(CreateSalesEntry::class);

        Bus::assertDispatched(CallWebhookJob::class, function (CallWebhookJob $job): bool {
            $signatureHeader = config('webhook-server.signature_header_name', 'Signature');
            $expectedSignature = hash_hmac('sha256', json_encode($job->payload), 'consumer-secret-xyz');

            return $job->webhookUrl === 'https://consumer.test/accounting'
                && $job->payload['event'] === 'accounting.document.synced'
                && $job->payload['status'] === 'posted'
                && $job->payload['external_id'] === 'INV-2026-001'
                && $job->payload['external_ref'] === 'inv-guid-9'
                && ($job->headers[$signatureHeader] ?? null) === $expectedSignature;
        });

        $this->assertDatabaseHas('pass_through_calls', [
            'direction' => 'outbound',
            'provider' => 'exact',
            'path' => 'accounting/documents:sales_invoice',
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
            return $job->payload['event'] === 'accounting.document.synced'
                && $job->payload['status'] === 'failed'
                && $job->payload['external_id'] === 'INV-2026-001';
        });
    }

    public function test_job_does_not_retry_to_avoid_double_booking(): void
    {
        [, $account, $connection] = $this->consumerWithExactConnection();
        $document = FinancialDocument::fromArray($this->salesInvoicePayload());

        $job = new SyncAccountingDocumentJob($document, $connection, $account, 1);

        // Exact heeft geen idempotency-key → de push mag nooit herhaald worden.
        $this->assertSame(1, $job->tries);
    }
}
