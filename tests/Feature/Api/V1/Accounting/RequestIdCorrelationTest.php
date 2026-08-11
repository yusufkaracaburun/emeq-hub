<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Accounting;

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
use Tests\Concerns\BindsFakeAccountingReferences;
use Tests\TestCase;

/**
 * Het correlatie-id moet de hele keten overleven: consumer-request → audit-rij →
 * partner-call → resultaat-webhook. Zonder deze dekking valt de keten stil op de
 * eerste plek waar iemand vergeet 'm door te geven.
 */
class RequestIdCorrelationTest extends TestCase
{
    use BindsFakeAccountingReferences;
    use RefreshDatabase;

    private const REQUEST_ID = 'corr-test-000000001';

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
     * @return array{0: Consumer, 1: Connection}
     */
    private function consumerWithExactConnection(array $consumerState = []): array
    {
        $consumer = $consumerState === []
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

        return [$consumer, $connection];
    }

    /**
     * @return array<string, mixed>
     */
    private function salesInvoicePayload(): array
    {
        return [
            'type' => 'sales_invoice',
            'external_id' => 'INV-CORR-001',
            'number' => '2026-777',
            'issue_date' => '2026-06-16',
            'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'vat_number' => 'NL000099998B57'],
            'lines' => [
                ['description' => 'Consultancy', 'amount' => 200, 'tax_rate' => 21, 'category' => 'omzet'],
            ],
        ];
    }

    public function test_request_id_lands_on_the_pass_through_call(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-corr']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->withHeader('X-Request-Id', self::REQUEST_ID)
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(201);

        $this->assertDatabaseHas('pass_through_calls', [
            'path' => '/v1/accounting/documents',
            'provider' => 'exact',
            'request_id' => self::REQUEST_ID,
        ]);
    }

    /**
     * De audit-writer weet niets van correlatie — de model-hook vult 'm. Deze test
     * bewijst dat de hook vuurt in plaats van dat één writer het toevallig doet.
     */
    public function test_request_id_is_generated_when_the_consumer_sends_none(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-corr2']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload());

        $response->assertStatus(201);

        $this->assertDatabaseHas('pass_through_calls', [
            'path' => '/v1/accounting/documents',
            'request_id' => $response->headers->get('X-Request-Id'),
        ]);
    }

    public function test_request_id_travels_into_the_result_webhook_headers(): void
    {
        Bus::fake([CallWebhookJob::class]);
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-corr3']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection([
            'url' => 'https://consumer.test/accounting',
            'secret' => 'consumer-secret-xyz',
        ]);
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->withHeader('X-Request-Id', self::REQUEST_ID)
            ->withHeader('Prefer', 'respond-async')
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(202);

        Bus::assertDispatched(
            CallWebhookJob::class,
            fn (CallWebhookJob $job): bool => ($job->headers['X-Emeq-Request-Id'] ?? null) === self::REQUEST_ID
                && ($job->headers['X-Emeq-Event-Id'] ?? null) !== null
        );
    }
}
