<?php

namespace Tests\Feature\Api\V1\Accounting;

use App\Accounting\Enums\DocumentType;
use App\Accounting\Exact\Contracts\ExactReferenceResolver;
use App\Accounting\Party;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Http\Request\Write\CreateGeneralJournalEntry;
use Emeq\ExactApi\Http\Request\Write\CreatePurchaseEntry;
use Emeq\ExactApi\Http\Request\Write\CreateSalesEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
            public function relationGuid(Party $party, Connection $connection): string
            {
                return $party->role === 'creditor' ? 'supp-guid' : 'cust-guid';
            }

            public function vatCode(float $taxRate, Connection $connection): string
            {
                return $taxRate >= 21.0 ? '4' : '2';
            }

            public function glAccountGuid(?string $category, Connection $connection): ?string
            {
                return 'gl-guid';
            }

            public function journal(DocumentType $type, Connection $connection): string
            {
                return $type === DocumentType::PurchaseInvoice ? '20' : '90';
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
                ['description' => 'Consultancy', 'amount' => 200, 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 21, 'category' => 'omzet'],
            ],
        ], $overrides);
    }

    public function test_pushes_canonical_sales_invoice_to_exact(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
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

    public function test_successful_push_returns_posted_status(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(201)
            ->assertJsonPath('status', 'posted');
    }

    public function test_maps_canonical_to_exact_salesinvoice_body(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(201);

        MockClient::global()->assertSent(function (CreateSalesEntry $request): bool {
            $body = $request->body()->all();

            return $request->resolveEndpoint() === '/salesentry/SalesEntries'
                && $body['Customer'] === 'cust-guid'
                && $body['Journal'] === '90'
                && $body['SalesEntryLines'][0]['VATCode'] === '4'
                && $body['SalesEntryLines'][0]['GLAccount'] === 'gl-guid'
                && (float) $body['SalesEntryLines'][0]['AmountFC'] === 200.0;
        });
    }

    public function test_uses_connection_metadata_mapping(): void
    {
        // Geen fake → de echte ConnectionMappingExactReferenceResolver leest metadata.
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
        ]);

        [$consumer] = $this->consumerWithExactConnection([
            'metadata' => ['accounting_mapping' => [
                'vat_codes' => ['21' => '4'],
                'gl_accounts' => ['_default' => 'gl-def'],
                'relations' => ['acme-1' => 'cust-real'],
                'journals' => ['sales' => '70'],
            ]],
        ]);
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'external_id' => 'acme-1'],
            ]))
            ->assertStatus(201);

        MockClient::global()->assertSent(function (CreateSalesEntry $request): bool {
            $body = $request->body()->all();

            return $body['Customer'] === 'cust-real'
                && $body['Journal'] === '70'
                && $body['SalesEntryLines'][0]['VATCode'] === '4'
                && $body['SalesEntryLines'][0]['GLAccount'] === 'gl-def';
        });
    }

    public function test_line_amount_drives_booking_without_quantity_or_price(): void
    {
        MockClient::global([
            CreatePurchaseEntry::class => MockResponse::make(['d' => ['ID' => 'pe-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'type' => 'purchase_invoice',
                'party' => ['role' => 'creditor', 'name' => 'Leverancier BV'],
                'lines' => [
                    ['description' => 'Dienst', 'amount' => 250.50, 'tax_rate' => 21, 'category' => 'kosten'],
                ],
            ]))
            ->assertStatus(201);

        MockClient::global()->assertSent(function (CreatePurchaseEntry $request): bool {
            $body = $request->body()->all();

            return (float) $body['PurchaseEntryLines'][0]['AmountFC'] === 250.50;
        });
    }

    public function test_pushes_purchase_invoice_to_purchaseentry(): void
    {
        MockClient::global([
            CreatePurchaseEntry::class => MockResponse::make(['d' => ['ID' => 'pe-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'type' => 'purchase_invoice',
                'party' => ['role' => 'creditor', 'name' => 'Leverancier BV'],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('external_ref', 'pe-1');

        MockClient::global()->assertSent(function (CreatePurchaseEntry $request): bool {
            $body = $request->body()->all();

            return $request->resolveEndpoint() === '/purchaseentry/PurchaseEntries'
                && $body['Supplier'] === 'supp-guid'
                && $body['Journal'] === '20'
                && $body['PurchaseEntryLines'][0]['VATCode'] === '4';
        });

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'exact',
            'path' => 'accounting/documents:purchase_invoice',
            'status' => 201,
        ]);
    }

    public function test_pushes_expense_to_generaljournalentry(): void
    {
        MockClient::global([
            CreateGeneralJournalEntry::class => MockResponse::make(['d' => ['EntryID' => 'gj-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'type' => 'expense',
                'party' => ['role' => 'creditor', 'name' => 'Leverancier BV'],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('external_ref', 'gj-1');

        MockClient::global()->assertSent(function (CreateGeneralJournalEntry $request): bool {
            $body = $request->body()->all();

            // Exact weigert op een GeneralJournalEntry zowel een header-Description als
            // een regel-VATCode (live-geverifieerd 2026-06-17).
            return $request->resolveEndpoint() === '/generaljournalentry/GeneralJournalEntries'
                && ! array_key_exists('Description', $body)
                && $body['JournalCode'] === '90'
                && (float) $body['GeneralJournalEntryLines'][0]['AmountDC'] === 200.0
                && ! array_key_exists('VATCode', $body['GeneralJournalEntryLines'][0]);
        });

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'exact',
            'path' => 'accounting/documents:expense',
            'status' => 201,
        ]);
    }

    public function test_missing_idempotency_key_returns_400(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'x']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(400)
            ->assertJson(['error' => 'idempotency_key_required']);

        MockClient::global()->assertNothingSent();
    }

    public function test_retry_with_same_idempotency_key_books_once(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;
        $key = (string) Str::uuid();

        foreach ([1, 2] as $attempt) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->withHeader('X-Account-Id', 'school1')
                ->withHeader('Idempotency-Key', $key)
                ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
                ->assertStatus(201)
                ->assertJsonPath('external_ref', 'inv-1');
        }

        // Twee POSTs met dezelfde key → één boeking bij Exact, tweede is een replay.
        MockClient::global()->assertSentCount(1);
    }

    public function test_missing_account_header_returns_400(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Idempotency-Key', (string) Str::uuid())
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
            ->withHeader('Idempotency-Key', (string) Str::uuid())
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
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(403)
            ->assertJson(['error' => 'insufficient_ability']);
    }

    public function test_unconfigured_reference_mapping_returns_422(): void
    {
        // Geen fake gebonden → DefaultExactReferenceResolver gooit → mapping_failed.
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'x']], 201),
        ]);

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
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
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload(['type' => 'bogus']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }
}
