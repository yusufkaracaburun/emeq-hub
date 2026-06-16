<?php

namespace Tests\Feature\Api\V1\Accounting;

use App\Accounting\Exact\Contracts\ExactReferenceResolver;
use App\Accounting\Party;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Http\Request\RawExactRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

class StoreDocumentTest extends TestCase
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
            public function customerGuid(Party $party, Connection $connection): string
            {
                return 'cust-guid';
            }

            public function vatCode(float $taxRate, Connection $connection): string
            {
                return $taxRate >= 21.0 ? '4' : '2';
            }

            public function glAccountGuid(?string $category, Connection $connection): ?string
            {
                return 'gl-guid';
            }
        });
    }

    /**
     * @param  array<string, mixed>  $connectionState
     * @return array{0: Consumer, 1: Connection}
     */
    private function consumerWithExactConnection(array $connectionState = []): array
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);

        $connection = Connection::factory()->forExact()->create(array_merge([
            'account_id' => $account->id,
            'status' => 'active',
            'expires_at' => now()->addSeconds(600),
        ], $connectionState));

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
            'external_id' => 'INV-2026-001',
            'number' => '2026-001',
            'issue_date' => '2026-06-16',
            'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'vat_number' => 'NL000099998B57'],
            'lines' => [
                ['description' => 'Consultancy', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 21, 'category' => 'omzet'],
            ],
        ], $overrides);
    }

    public function test_pushes_canonical_sales_invoice_to_exact(): void
    {
        MockClient::global([
            RawExactRequest::class => MockResponse::make(['d' => ['ID' => 'inv-guid-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(201)
            ->assertJsonPath('provider', 'exact')
            ->assertJsonPath('external_ref', 'inv-guid-1');

        $this->assertDatabaseHas('pass_through_calls', [
            'direction' => 'outbound',
            'provider' => 'exact',
            'method' => 'POST',
            'path' => 'accounting/documents:sales_invoice',
            'status' => 201,
        ]);
    }

    public function test_maps_canonical_to_exact_salesinvoice_body(): void
    {
        MockClient::global([
            RawExactRequest::class => MockResponse::make(['d' => ['ID' => 'inv-guid-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(201);

        MockClient::global()->assertSent(function (RawExactRequest $request): bool {
            $body = $request->body()->all();

            return $request->resolveEndpoint() === '/salesinvoice/SalesInvoices'
                && $body['Customer'] === 'cust-guid'
                && $body['SalesInvoiceLines'][0]['VATCode'] === '4'
                && $body['SalesInvoiceLines'][0]['GLAccount'] === 'gl-guid'
                && (float) $body['SalesInvoiceLines'][0]['UnitPrice'] === 100.0;
        });
    }

    public function test_missing_account_header_returns_400(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(400)
            ->assertJson(['error' => 'missing_account_header']);
    }

    public function test_no_accounting_connection_returns_404(): void
    {
        $consumer = Consumer::factory()->create();
        $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'S1']);
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(404)
            ->assertJson(['error' => 'no_accounting_connection']);
    }

    public function test_without_write_ability_returns_403(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(403)
            ->assertJson(['error' => 'insufficient_ability']);
    }

    public function test_unconfigured_reference_mapping_returns_422(): void
    {
        // Geen fake gebonden → DefaultExactReferenceResolver gooit → mapping_failed.
        MockClient::global([
            RawExactRequest::class => MockResponse::make(['d' => ['ID' => 'x']], 201),
        ]);

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(422)
            ->assertJson(['error' => 'mapping_failed']);
    }

    public function test_validation_rejects_unknown_document_type(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload(['type' => 'bogus']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }
}
