<?php

namespace Tests\Feature\Api\V1\Accounting;

use App\Accounting\Enums\DocumentType;
use App\Accounting\Exact\Contracts\ExactReferenceResolver;
use App\Accounting\Party;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Http\Request\Read\GetRelations;
use Emeq\ExactApi\Http\Request\Write\CreateAccount;
use Emeq\ExactApi\Http\Request\Write\CreateDocument;
use Emeq\ExactApi\Http\Request\Write\CreateDocumentAttachment;
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
                return in_array($type, [DocumentType::PurchaseInvoice, DocumentType::Expense], true) ? '20' : '90';
            }

            public function costCenter(?string $code, Connection $connection): ?string
            {
                return $code;
            }

            public function costUnit(?string $code, Connection $connection): ?string
            {
                return $code;
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
            'path' => '/v1/accounting/documents',
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

    public function test_maps_cost_center_and_unit_onto_the_entry_line(): void
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
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'lines' => [
                    ['description' => 'Consultancy', 'amount' => 200, 'tax_rate' => 21, 'category' => 'omzet', 'cost_center' => 'ADMIN', 'cost_unit' => 'PROJ-X'],
                ],
            ]))
            ->assertStatus(201);

        MockClient::global()->assertSent(function (CreateSalesEntry $request): bool {
            $line = $request->body()->all()['SalesEntryLines'][0];

            return $line['CostCenter'] === 'ADMIN' && $line['CostUnit'] === 'PROJ-X';
        });
    }

    public function test_omits_cost_center_and_unit_when_absent(): void
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
            $line = $request->body()->all()['SalesEntryLines'][0];

            return ! array_key_exists('CostCenter', $line) && ! array_key_exists('CostUnit', $line);
        });
    }

    public function test_response_echoes_external_id_and_status(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(201)
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('external_id', 'INV-2026-001');
    }

    public function test_stamps_consumer_provenance_in_yourref(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(201);

        // YourRef stempelt de herkomst: "{consumer-app} · {external_id}" (max 50 tekens).
        $expected = mb_substr($consumer->name.' · INV-2026-001', 0, 50);
        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateSalesEntry
            && ($request->body()->all()['YourRef'] ?? null) === $expected);
    }

    public function test_uses_connection_metadata_mapping(): void
    {
        // Geen fake → de echte ConnectionMappingExactReferenceResolver. Mapping draagt enkel
        // stabiele Codes; GL-Code + relatie resolven lokaal naar native-ID via de mirror.
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
        ]);

        [$consumer, $connection] = $this->consumerWithExactConnection([
            'metadata' => ['accounting_mapping' => [
                'vat_codes' => ['21' => '4'],
                'gl_accounts' => ['_default' => 'gl-def'],
                'journals' => ['sales' => '70'],
            ]],
        ]);

        // Mirror: GL-Code → native GUID, en de lazy-geleerde relatie external_id → GUID.
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_GL,
            'code' => 'gl-def',
            'native_id' => 'gl-def-guid',
        ]);
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'acme-1',
            'native_id' => 'cust-real',
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
                && $body['SalesEntryLines'][0]['GLAccount'] === 'gl-def-guid';
        });
    }

    public function test_auto_creates_relation_when_opt_in_and_no_match(): void
    {
        // Geen fake → de echte resolver. Opt-in aan + geen match → relatie wordt in Exact
        // aangemaakt (crm/Accounts) en geleerd, daarna boekt de verkoopboeking erop.
        MockClient::global([
            GetRelations::class => MockResponse::make(['d' => ['results' => []]], 200),
            CreateAccount::class => MockResponse::make(['d' => ['ID' => 'new-rel-guid']], 201),
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
        ]);

        [$consumer, $connection] = $this->consumerWithExactConnection([
            'metadata' => ['accounting_mapping' => [
                'vat_codes' => ['21' => '4'],
                'gl_accounts' => ['_default' => 'gl-def'],
                'journals' => ['sales' => '70'],
                'auto_create_relations' => true,
            ]],
        ]);

        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_GL,
            'code' => 'gl-def',
            'native_id' => 'gl-def-guid',
        ]);

        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'party' => ['role' => 'debtor', 'name' => 'Nieuwe Klant', 'external_id' => 'nieuw-1'],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('external_ref', 'inv-1');

        MockClient::global()->assertSent(function ($request): bool {
            if (! $request instanceof CreateAccount) {
                return false;
            }

            $body = $request->body()->all();

            return $body['Name'] === 'Nieuwe Klant'
                && $body['Status'] === 'C'
                && $body['IsSales'] === true;
        });

        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateSalesEntry && $request->body()->all()['Customer'] === 'new-rel-guid');

        $this->assertDatabaseHas('connection_accounting_refs', [
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'nieuw-1',
            'native_id' => 'new-rel-guid',
        ]);
    }

    public function test_unknown_relation_without_opt_in_returns_422(): void
    {
        // Opt-in staat default uit → geen match blijft een 422; er wordt géén relatie aangemaakt.
        MockClient::global([
            GetRelations::class => MockResponse::make(['d' => ['results' => []]], 200),
        ]);

        [$consumer, $connection] = $this->consumerWithExactConnection([
            'metadata' => ['accounting_mapping' => [
                'vat_codes' => ['21' => '4'],
                'gl_accounts' => ['_default' => 'gl-def'],
                'journals' => ['sales' => '70'],
            ]],
        ]);

        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_GL,
            'code' => 'gl-def',
            'native_id' => 'gl-def-guid',
        ]);

        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'party' => ['role' => 'debtor', 'name' => 'Onbekende Klant', 'external_id' => 'onbekend-1'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'mapping_failed');

        MockClient::global()->assertNotSent(CreateAccount::class);

        $this->assertDatabaseMissing('connection_accounting_refs', [
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'onbekend-1',
        ]);
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
            'path' => '/v1/accounting/documents',
            'status' => 201,
        ]);
    }

    /**
     * income = ontvangst met relatie als debiteur → SalesEntry (geen memoriaal): elke
     * income/expense draagt altijd een relatie + categorie + (eventueel) BTW, dus het
     * is een gewone verkoop-/inkoopboeking, niet een relatieloze GL-mutatie. Zie #12.
     */
    public function test_pushes_income_to_salesentry(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inc-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'type' => 'income',
                'party' => ['role' => 'debtor', 'name' => 'Klant BV'],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('external_ref', 'inc-1');

        MockClient::global()->assertSent(function (CreateSalesEntry $request): bool {
            $body = $request->body()->all();

            return $request->resolveEndpoint() === '/salesentry/SalesEntries'
                && $body['Customer'] === 'cust-guid'
                && $body['SalesEntryLines'][0]['VATCode'] === '4';
        });

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'exact',
            'path' => '/v1/accounting/documents',
            'status' => 201,
        ]);
    }

    public function test_pushes_expense_to_purchaseentry(): void
    {
        MockClient::global([
            CreatePurchaseEntry::class => MockResponse::make(['d' => ['ID' => 'exp-1']], 201),
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
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('external_ref', 'exp-1');

        // expense = declaratie/kosten met relatie als crediteur → PurchaseEntry.
        MockClient::global()->assertSent(function (CreatePurchaseEntry $request): bool {
            $body = $request->body()->all();

            return $request->resolveEndpoint() === '/purchaseentry/PurchaseEntries'
                && $body['Supplier'] === 'supp-guid'
                && $body['Journal'] === '20'
                && $body['PurchaseEntryLines'][0]['VATCode'] === '4';
        });
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

    public function test_sales_invoice_with_attachment_uploads_document_then_attachment(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-1']], 201),
            CreateDocument::class => MockResponse::make(['d' => ['ID' => 'doc-guid-1']], 201),
            CreateDocumentAttachment::class => MockResponse::make(['d' => ['ID' => 'att-guid-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'attachments' => [
                    ['filename' => 'factuur.pdf', 'mime_type' => 'application/pdf', 'content' => 'JVBERi0xLjQK'],
                ],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('external_ref', 'inv-guid-1')
            ->assertJsonPath('attachments.0.status', 'uploaded')
            ->assertJsonPath('attachments.0.document_ref', 'doc-guid-1');

        // Document gekoppeld aan de boeking (FinancialTransactionEntryID = SalesEntry-ID)
        // en aan de relatie (Account), met de gegronde DocumentType 10 (Sales invoice).
        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateDocument
            && $request->resolveEndpoint() === '/documents/Documents'
            && $request->body()->all()['Type'] === 10
            && $request->body()->all()['Account'] === 'cust-guid'
            && $request->body()->all()['FinancialTransactionEntryID'] === 'inv-guid-1');

        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateDocumentAttachment
            && $request->body()->all()['Document'] === 'doc-guid-1'
            && $request->body()->all()['FileName'] === 'factuur.pdf'
            && $request->body()->all()['Attachment'] === 'JVBERi0xLjQK');
    }

    public function test_purchase_attachment_reuses_exacts_auto_document(): void
    {
        // PurchaseEntry-respons draagt Exact's auto-Document (d.Document); de bijlage hangt
        // daaraan — géén tweede CreateDocument (anders dubbel document op de inkoopfactuur).
        MockClient::global([
            CreatePurchaseEntry::class => MockResponse::make(['d' => ['ID' => 'pe-1', 'Document' => 'auto-doc-1']], 201),
            CreateDocumentAttachment::class => MockResponse::make(['d' => ['ID' => 'att-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'type' => 'purchase_invoice',
                'party' => ['role' => 'creditor', 'name' => 'Leverancier BV', 'external_id' => 'sup-1'],
                'attachments' => [
                    ['filename' => 'sbi.pdf', 'mime_type' => 'application/pdf', 'content' => 'JVBERi0xLjQK'],
                ],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('attachments.0.status', 'uploaded')
            ->assertJsonPath('attachments.0.document_ref', 'auto-doc-1');

        MockClient::global()->assertNotSent(CreateDocument::class);
        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateDocumentAttachment
            && $request->body()->all()['Document'] === 'auto-doc-1');
    }

    public function test_without_attachments_no_document_calls_are_made(): void
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
            ->assertJsonMissingPath('attachments');

        MockClient::global()->assertNotSent(CreateDocument::class);
        MockClient::global()->assertNotSent(CreateDocumentAttachment::class);
        MockClient::global()->assertSentCount(1);
    }

    public function test_attachment_with_unsupported_mime_returns_422(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'attachments' => [
                    ['filename' => 'virus.exe', 'mime_type' => 'application/x-msdownload', 'content' => 'AAAA'],
                ],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments.0.mime_type');
    }

    public function test_attachment_over_size_limit_returns_422(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'attachments' => [
                    ['filename' => 'big.pdf', 'mime_type' => 'application/pdf', 'content' => str_repeat('A', 1_400_001)],
                ],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments.0.content');
    }
}
