<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Accounting;

use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Http\Request\Write\CreateDocument;
use Emeq\ExactApi\Http\Request\Write\CreateDocumentAttachment;
use Emeq\ExactApi\Http\Request\Write\CreateSalesEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Concerns\BindsFakeAccountingReferences;
use Tests\TestCase;

class AttachmentUploadRetryTest extends TestCase
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
            // Connector zelf retryt al op 429 met een vast 1s-interval — irrelevant
            // voor wat hier getest wordt. Forceer één fysieke poging per send() zodat
            // alleen ExactAccountingTarget's eigen bounded retry wordt geoefend.
            'exact.http.retry' => ['times' => 1, 'sleep' => 0, 'on' => [429, 500, 502, 503, 504]],
        ]);
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();

        parent::tearDown();
    }

    /** @return array{0: Consumer, 1: Connection} */
    private function consumerWithExactConnection(): array
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);

        $connection = Connection::factory()->forExact()->create([
            'account_id' => $account->id,
            'status' => 'active',
            'expires_at' => now()->addSeconds(600),
        ]);

        return [$consumer, $connection];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function salesInvoicePayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'sales_invoice',
            'external_id' => 'INV-RETRY-001',
            'number' => '2026-001',
            'issue_date' => '2026-06-16',
            'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'kind' => 'company', 'external_id' => 'acme-1', 'vat_number' => 'NL000099998B57'],
            'lines' => [
                ['description' => 'Consultancy', 'amount' => 200, 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 21, 'category' => 'omzet'],
            ],
            'attachments' => [
                ['filename' => 'factuur.pdf', 'mime_type' => 'application/pdf', 'content' => 'JVBERi0xLjQK'],
            ],
        ], $overrides);
    }

    public function test_attachment_upload_recovers_after_one_rate_limited_retry(): void
    {
        $attempts = 0;

        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-1']], 201),
            CreateDocument::class => MockResponse::make(['d' => ['ID' => 'doc-guid-1']], 201),
            CreateDocumentAttachment::class => function () use (&$attempts): MockResponse {
                $attempts++;

                return $attempts === 1
                    ? MockResponse::make('rate limited', 429, ['Retry-After' => '1'])
                    : MockResponse::make(['d' => ['ID' => 'att-guid-1']], 201);
            },
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(201)
            ->assertJsonPath('external_ref', 'inv-guid-1')
            ->assertJsonPath('attachments.0.status', 'uploaded')
            ->assertJsonPath('attachments.0.document_ref', 'doc-guid-1');

        $this->assertSame(2, $attempts);
    }

    public function test_attachment_upload_fails_and_logs_when_rate_limit_has_no_retry_after(): void
    {
        Log::spy();

        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-1']], 201),
            CreateDocument::class => MockResponse::make(['d' => ['ID' => 'doc-guid-1']], 201),
            CreateDocumentAttachment::class => MockResponse::make('rate limited', 429),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(201)
            ->assertJsonPath('external_ref', 'inv-guid-1')
            ->assertJsonPath('attachments.0.status', 'failed');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'accounting.attachment_upload_failed'
                && $context['filename'] === 'factuur.pdf'
                && $context['external_id'] === 'INV-RETRY-001'
                && $context['provider'] === 'exact');
    }
}
