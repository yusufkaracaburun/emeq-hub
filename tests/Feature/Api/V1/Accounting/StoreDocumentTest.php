<?php

namespace Tests\Feature\Api\V1\Accounting;

use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\Consumer;
use App\Models\PassThroughCall;
use App\Models\ProviderEntityLink;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Http\Request\Read\GetRelations;
use Emeq\ExactApi\Http\Request\Write\CreateAccount;
use Emeq\ExactApi\Http\Request\Write\CreateDocument;
use Emeq\ExactApi\Http\Request\Write\CreateDocumentAttachment;
use Emeq\ExactApi\Http\Request\Write\CreatePurchaseEntry;
use Emeq\ExactApi\Http\Request\Write\CreateSalesEntry;
use Emeq\ExactApi\Http\Request\Write\UpdateAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Tests\Concerns\BindsFakeAccountingReferences;
use Tests\TestCase;

class StoreDocumentTest extends TestCase
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
            'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'kind' => 'company', 'external_id' => 'acme-1', 'vat_number' => 'NL000099998B57'],
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

    public function test_invalid_nl_vat_checksum_is_rejected_at_edge_before_exact(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'party' => ['role' => 'debtor', 'name' => 'GroenAanleg Zuid', 'kind' => 'company', 'external_id' => 'groen-1', 'vat_number' => 'NL123456789B01'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('party.vat_number');

        $this->assertDatabaseCount('pass_through_calls', 0);
    }

    public function test_invalid_iban_checksum_is_rejected_at_edge_before_exact(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'kind' => 'company', 'external_id' => 'acme-1', 'vat_number' => 'NL000099998B57', 'iban' => 'NL91ABNA0417164301'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('party.iban');

        $this->assertDatabaseCount('pass_through_calls', 0);
    }

    public function test_iban_with_spaces_and_lowercase_still_books(): void
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
                'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'kind' => 'company', 'external_id' => 'acme-1', 'vat_number' => 'NL000099998B57', 'iban' => 'nl91 abna 0417 1643 00'],
            ]))
            ->assertStatus(201);
    }

    public function test_response_echoes_exact_entry_number_when_present(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-1', 'EntryNumber' => 60001]], 201),
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
            ->assertJsonPath('external_number', 60001);
    }

    public function test_response_omits_external_number_when_exact_returns_none(): void
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
            ->assertJsonMissingPath('external_number');
    }

    public function test_exact_functional_rejection_returns_422_with_clear_message(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(
                ['error' => ['message' => ['value' => 'Ongeldig controlecijfer voor btw-nummer.']]],
                500,
            ),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'upstream_rejected');

        $this->assertStringContainsString('btw-nummer is ongeldig', $response->json('message'));
        $this->assertStringContainsString('controlecijfer', $response->json('provider_message'));

        $this->assertDatabaseHas('pass_through_calls', [
            'provider' => 'exact',
            'path' => '/v1/accounting/documents',
            'status' => 422,
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

    public function test_defaults_due_date_to_one_month_after_issue_date(): void
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
            return $request->body()->all()['DueDate'] === '2026-07-16';
        });
    }

    public function test_passes_explicit_due_date_to_exact(): void
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
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload(['due_date' => '2026-08-01']))
            ->assertStatus(201);

        MockClient::global()->assertSent(function (CreateSalesEntry $request): bool {
            return $request->body()->all()['DueDate'] === '2026-08-01';
        });
    }

    public function test_reverse_charge_line_maps_to_verlegd_vat_code(): void
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
                    ['description' => 'Onderaanneming', 'amount' => 200, 'tax_rate' => 21, 'tax_treatment' => 'reverse_charge', 'category' => 'omzet'],
                ],
            ]))
            ->assertStatus(201);

        MockClient::global()->assertSent(function (CreateSalesEntry $request): bool {
            return $request->body()->all()['SalesEntryLines'][0]['VATCode'] === '6';
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

    public function test_yourref_leads_with_the_document_number_a_bookkeeper_reads(): void
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

        $expected = '2026-001 · INV-2026-001';
        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateSalesEntry
            && ($request->body()->all()['YourRef'] ?? null) === $expected);
    }

    public function test_yourref_falls_back_to_the_consumer_when_a_document_has_no_number(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $consumer->update(['name' => 'Emeq']);
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $payload = $this->salesInvoicePayload();
        unset($payload['number']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $payload)
            ->assertStatus(201);

        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateSalesEntry
            && ($request->body()->all()['YourRef'] ?? null) === 'Emeq · INV-2026-001');
    }

    public function test_yourref_drops_the_external_id_when_it_does_not_fit_whole(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $payload = $this->salesInvoicePayload();
        $payload['external_id'] = '877f9972-3969-4d9c-9405-356a18072bed';

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $payload)
            ->assertStatus(201);

        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateSalesEntry
            && ($request->body()->all()['YourRef'] ?? null) === '2026-001');
    }

    public function test_description_carries_the_consumers_own_reference(): void
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
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload(['reference' => 'BOB260684']))
            ->assertStatus(201);

        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateSalesEntry
            && ($request->body()->all()['Description'] ?? null) === 'BOB260684');
    }

    public function test_description_falls_back_to_the_number_without_a_reference(): void
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

        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateSalesEntry
            && ($request->body()->all()['Description'] ?? null) === '2026-001');
    }

    public function test_uses_connection_metadata_mapping(): void
    {
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
                'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'kind' => 'company', 'external_id' => 'acme-1', 'chamber_of_commerce' => '12345678'],
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

    public function test_sales_invoice_line_without_category_uses_sales_default_not_purchase_default(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
        ]);

        [$consumer, $connection] = $this->consumerWithExactConnection([
            'metadata' => ['accounting_mapping' => [
                'vat_codes' => ['21' => '4'],
                'gl_accounts' => ['_default' => 'gl-kosten-guid', 'sales_default' => 'gl-omzet'],
                'journals' => ['sales' => '70'],
            ]],
        ]);

        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_GL,
            'code' => 'gl-omzet',
            'native_id' => 'gl-omzet-guid',
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
                'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'kind' => 'company', 'external_id' => 'acme-1', 'chamber_of_commerce' => '12345678'],
                'lines' => [
                    ['description' => 'Consultancy', 'amount' => 200, 'tax_rate' => 21],
                ],
            ]))
            ->assertStatus(201);

        MockClient::global()->assertSent(fn (CreateSalesEntry $request): bool => $request->body()->all()['SalesEntryLines'][0]['GLAccount'] === 'gl-omzet-guid');
    }

    public function test_purchase_invoice_line_without_category_uses_purchase_default(): void
    {
        MockClient::global([
            CreatePurchaseEntry::class => MockResponse::make(['d' => ['ID' => 'pe-1']], 201),
        ]);

        [$consumer, $connection] = $this->consumerWithExactConnection([
            'metadata' => ['accounting_mapping' => [
                'vat_codes' => ['21' => '4'],
                'gl_accounts' => ['_default' => 'gl-omzet-guid', 'purchase_default' => 'gl-kosten'],
                'journals' => ['purchase' => '20'],
            ]],
        ]);

        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_GL,
            'code' => 'gl-kosten',
            'native_id' => 'gl-kosten-guid',
        ]);
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'leverancier-1',
            'native_id' => 'supp-real',
        ]);

        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'type' => 'purchase_invoice',
                'party' => ['role' => 'creditor', 'name' => 'Leverancier BV', 'kind' => 'company', 'external_id' => 'leverancier-1', 'chamber_of_commerce' => '87654321'],
                'lines' => [
                    ['description' => 'Inkoop', 'amount' => 100, 'tax_rate' => 21],
                ],
            ]))
            ->assertStatus(201);

        MockClient::global()->assertSent(fn (CreatePurchaseEntry $request): bool => $request->body()->all()['PurchaseEntryLines'][0]['GLAccount'] === 'gl-kosten-guid');
    }

    public function test_auto_created_relation_carries_the_whole_relation_card(): void
    {
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
                'party' => [
                    'role' => 'debtor',
                    'name' => 'Nieuwe Klant BV',
                    'kind' => 'company',
                    'external_id' => 'nieuw-1',
                    'vat_number' => 'NL000099998B57',
                    'chamber_of_commerce' => '12345678',
                    'address_line_1' => 'Dorpsstraat 1',
                    'address_line_2' => 'Unit 4',
                    'postcode' => '1234 AB',
                    'city' => 'Amsterdam',
                    'state' => 'NH',
                    'country' => 'nl',
                    'email' => 'facturen@nieuweklant.nl',
                    'phone' => '+31201234567',
                    'website' => 'https://nieuweklant.nl',
                ],
            ]))
            ->assertStatus(201);

        MockClient::global()->assertSent(function ($request): bool {
            if (! $request instanceof CreateAccount) {
                return false;
            }

            return $request->body()->all() === [
                'Name' => 'Nieuwe Klant BV',
                'Status' => 'C',
                'IsSales' => true,
                'VATNumber' => 'NL000099998B57',
                'ChamberOfCommerce' => '12345678',
                'AddressLine1' => 'Dorpsstraat 1',
                'AddressLine2' => 'Unit 4',
                'Postcode' => '1234 AB',
                'City' => 'Amsterdam',
                'State' => 'NH',
                'Country' => 'NL',
                'Email' => 'facturen@nieuweklant.nl',
                'Phone' => '+31201234567',
                'Website' => 'https://nieuweklant.nl',
            ];
        });
    }

    public function test_person_party_without_a_strong_key_is_created_when_the_mirror_has_no_hit(): void
    {
        MockClient::global([
            CreateAccount::class => MockResponse::make(['d' => ['ID' => 'new-rel-guid']], 201),
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
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

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'party' => ['role' => 'debtor', 'name' => 'Jan Jansen', 'kind' => 'person', 'external_id' => 'nieuw-1'],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('external_ref', 'inv-1');

        $this->assertSame('relation.created', $response->json('warnings.0.code'));
        $this->assertSame('new-rel-guid', $response->json('warnings.0.context.relation_id'));

        $this->assertSame(
            'relation.created',
            PassThroughCall::query()
                ->where('path', '/v1/accounting/documents')
                ->latest('created_at')
                ->value('warnings')[0]['code'] ?? null,
        );

        MockClient::global()->assertNotSent(GetRelations::class);

        MockClient::global()->assertSent(function ($request): bool {
            if (! $request instanceof CreateAccount) {
                return false;
            }

            $body = $request->body()->all();

            return $body['Name'] === 'Jan Jansen'
                && $body['Status'] === 'C'
                && $body['IsSales'] === true;
        });

        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateSalesEntry && $request->body()->all()['Customer'] === 'new-rel-guid');

        $ref = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_RELATION)
            ->where('code', 'nieuw-1')
            ->first();

        $this->assertSame('new-rel-guid', $ref->native_id);
        $this->assertSame(['matched_on' => 'created'], $ref->attrs);

        $this->assertDatabaseHas('provider_entity_links', [
            'connection_id' => $connection->getKey(),
            'entity_type' => ProviderEntityLink::ENTITY_RELATION,
            'provider_entity_id' => 'new-rel-guid',
            'origin' => ProviderEntityLink::ORIGIN_HUB,
        ]);
    }

    public function test_auto_creates_creditor_relation_with_is_supplier_flag(): void
    {
        MockClient::global([
            GetRelations::class => MockResponse::make(['d' => ['results' => []]], 200),
            CreateAccount::class => MockResponse::make(['d' => ['ID' => 'new-supp-guid']], 201),
            CreatePurchaseEntry::class => MockResponse::make(['d' => ['ID' => 'pe-1']], 201),
        ]);

        [$consumer, $connection] = $this->consumerWithExactConnection([
            'metadata' => ['accounting_mapping' => [
                'vat_codes' => ['21' => '4'],
                'gl_accounts' => ['_default' => 'gl-def'],
                'journals' => ['purchase' => '70'],
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
                'type' => 'purchase_invoice',
                'party' => ['role' => 'creditor', 'name' => 'Nieuwe Leverancier', 'kind' => 'company', 'external_id' => 'nieuw-supp-1', 'chamber_of_commerce' => '87654321'],
            ]))
            ->assertStatus(201);

        MockClient::global()->assertSent(function ($request): bool {
            if (! $request instanceof CreateAccount) {
                return false;
            }

            $body = $request->body()->all();

            return $body['Name'] === 'Nieuwe Leverancier'
                && $body['IsSupplier'] === true
                && ! array_key_exists('IsSales', $body)
                && ! array_key_exists('Status', $body);
        });

        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreatePurchaseEntry && $request->body()->all()['Supplier'] === 'new-supp-guid');
    }

    public function test_company_party_without_chamber_of_commerce_or_vat_number_is_rejected_at_edge(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'party' => ['role' => 'debtor', 'name' => 'Onbekende Klant', 'kind' => 'company', 'external_id' => 'onbekend-1'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('party.chamber_of_commerce');

        $this->assertDatabaseCount('pass_through_calls', 0);
    }

    public function test_party_without_external_id_is_rejected_at_edge(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'party' => ['role' => 'debtor', 'name' => 'Nieuwe Klant Zonder ID', 'kind' => 'person'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('party.external_id');

        $this->assertDatabaseCount('pass_through_calls', 0);
    }

    public function test_booking_same_party_twice_creates_one_relation_via_the_mirror(): void
    {
        MockClient::global([
            CreateAccount::class => MockResponse::make(['d' => ['ID' => 'new-dup-guid']], 201),
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
            GetRelations::class => MockResponse::make(['d' => ['results' => [
                ['ID' => 'new-dup-guid', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]]], 200),
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
        $party = ['role' => 'debtor', 'name' => 'Dubbele Naam BV', 'kind' => 'person', 'external_id' => 'dup-1'];

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'external_id' => 'INV-DUP-1',
                'party' => $party,
            ]))
            ->assertStatus(201);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'external_id' => 'INV-DUP-2',
                'party' => $party,
            ]))
            ->assertStatus(201);

        MockClient::global()->assertSentCount(1, CreateAccount::class);

        $this->assertDatabaseCount('connection_accounting_refs', 2);

        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateSalesEntry && $request->body()->all()['Customer'] === 'new-dup-guid');
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
                'party' => ['role' => 'creditor', 'name' => 'Leverancier BV', 'kind' => 'company', 'external_id' => 'leverancier-1', 'chamber_of_commerce' => '87654321'],
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
                'party' => ['role' => 'creditor', 'name' => 'Leverancier BV', 'kind' => 'company', 'external_id' => 'leverancier-1', 'chamber_of_commerce' => '87654321'],
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
                'party' => ['role' => 'debtor', 'name' => 'Klant BV', 'kind' => 'company', 'external_id' => 'klant-1', 'chamber_of_commerce' => '12345678'],
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
                'party' => ['role' => 'creditor', 'name' => 'Leverancier BV', 'kind' => 'company', 'external_id' => 'leverancier-1', 'chamber_of_commerce' => '87654321'],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('external_ref', 'exp-1');

        MockClient::global()->assertSent(function (CreatePurchaseEntry $request): bool {
            $body = $request->body()->all();

            return $request->resolveEndpoint() === '/purchaseentry/PurchaseEntries'
                && $body['Supplier'] === 'supp-guid'
                && $body['Journal'] === '20'
                && $body['PurchaseEntryLines'][0]['VATCode'] === '4';
        });
    }

    public function test_promotes_existing_customer_relation_to_supplier_for_expense(): void
    {
        MockClient::global([
            GetRelations::class => MockResponse::make(['d' => ['results' => [[
                'ID' => 'cust-guid',
                'Code' => 'C001',
                'Name' => 'Bouwbedrijf Noord',
                'IsSales' => true,
                'IsSupplier' => false,
                'Status' => 'C',
            ]]]], 200),
            UpdateAccount::class => MockResponse::make([], 204),
            CreatePurchaseEntry::class => MockResponse::make(['d' => ['ID' => 'exp-1']], 201),
        ]);

        [$consumer, $connection] = $this->consumerWithExactConnection([
            'metadata' => ['accounting_mapping' => [
                'vat_codes' => ['9' => '1'],
                'gl_accounts' => ['kosten' => 'gl-kosten', '_default' => 'gl-kosten'],
                'journals' => ['purchase' => '70'],
            ]],
        ]);

        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_GL,
            'code' => 'gl-kosten',
            'native_id' => 'gl-kosten-guid',
        ]);

        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'type' => 'expense',
                'party' => ['role' => 'creditor', 'name' => 'Bouwbedrijf Noord', 'kind' => 'company', 'vat_number' => 'NL001234560B01', 'external_id' => 's1'],
                'lines' => [
                    ['description' => 'Betaling leverancier', 'amount' => 400, 'tax_rate' => 9, 'category' => 'kosten'],
                ],
            ]))
            ->assertStatus(201)
            ->assertJsonPath('external_ref', 'exp-1');

        MockClient::global()->assertSent(function ($request): bool {
            if (! $request instanceof UpdateAccount) {
                return false;
            }

            $body = $request->body()->all();

            return $request->resolveEndpoint() === "/crm/Accounts(guid'cust-guid')"
                && ($body['IsSupplier'] ?? null) === true
                && ! array_key_exists('IsSales', $body);
        });

        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreatePurchaseEntry
            && $request->body()->all()['Supplier'] === 'cust-guid');

        $this->assertDatabaseHas('connection_accounting_refs', [
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 's1',
            'native_id' => 'cust-guid',
        ]);
    }

    public function test_promotes_relation_from_mirror_when_supplier_flag_missing(): void
    {
        MockClient::global([
            GetRelations::class => MockResponse::make(['d' => ['results' => [[
                'ID' => 'cust-guid',
                'IsSales' => true,
                'IsSupplier' => false,
                'Status' => 'C',
            ]]]], 200),
            UpdateAccount::class => MockResponse::make([], 204),
            CreatePurchaseEntry::class => MockResponse::make(['d' => ['ID' => 'exp-1']], 201),
        ]);

        [$consumer, $connection] = $this->consumerWithExactConnection([
            'metadata' => ['accounting_mapping' => [
                'vat_codes' => ['9' => '1'],
                'gl_accounts' => ['kosten' => 'gl-kosten', '_default' => 'gl-kosten'],
                'journals' => ['purchase' => '70'],
            ]],
        ]);

        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_GL,
            'code' => 'gl-kosten',
            'native_id' => 'gl-kosten-guid',
        ]);
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 's1',
            'native_id' => 'cust-guid',
            'label' => 'Bouwbedrijf Noord',
        ]);

        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'type' => 'expense',
                'party' => ['role' => 'creditor', 'name' => 'Bouwbedrijf Noord', 'kind' => 'company', 'vat_number' => 'NL001234560B01', 'external_id' => 's1'],
                'lines' => [
                    ['description' => 'Betaling leverancier', 'amount' => 400, 'tax_rate' => 9, 'category' => 'kosten'],
                ],
            ]))
            ->assertStatus(201);

        MockClient::global()->assertSent(fn ($request): bool => $request instanceof UpdateAccount
            && ($request->body()->all()['IsSupplier'] ?? null) === true);
    }

    public function test_does_not_promote_when_relation_already_supplier(): void
    {
        MockClient::global([
            GetRelations::class => MockResponse::make(['d' => ['results' => [[
                'ID' => 'supp-guid',
                'Code' => 'S001',
                'Name' => 'Leverancier BV',
                'IsSales' => false,
                'IsSupplier' => true,
                'Status' => null,
            ]]]], 200),
            UpdateAccount::class => MockResponse::make([], 204),
            CreatePurchaseEntry::class => MockResponse::make(['d' => ['ID' => 'exp-1']], 201),
        ]);

        [$consumer, $connection] = $this->consumerWithExactConnection([
            'metadata' => ['accounting_mapping' => [
                'vat_codes' => ['9' => '1'],
                'gl_accounts' => ['kosten' => 'gl-kosten', '_default' => 'gl-kosten'],
                'journals' => ['purchase' => '70'],
            ]],
        ]);

        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_GL,
            'code' => 'gl-kosten',
            'native_id' => 'gl-kosten-guid',
        ]);

        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'type' => 'expense',
                'party' => ['role' => 'creditor', 'name' => 'Leverancier BV', 'kind' => 'company', 'vat_number' => 'NL001234560B01', 'external_id' => 's2'],
                'lines' => [
                    ['description' => 'Betaling leverancier', 'amount' => 400, 'tax_rate' => 9, 'category' => 'kosten'],
                ],
            ]))
            ->assertStatus(201);

        MockClient::global()->assertNotSent(UpdateAccount::class);
    }

    public function test_matches_relation_by_chamber_of_commerce(): void
    {
        MockClient::global([
            GetRelations::class => MockResponse::make(['d' => ['results' => [[
                'ID' => 'kvk-guid', 'Code' => 'K001', 'Name' => 'KvK Klant BV', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C',
                'ChamberOfCommerce' => '11223344', 'VATNumber' => '',
            ]]]], 200),
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
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
                'party' => ['role' => 'debtor', 'name' => 'KvK Klant BV', 'kind' => 'company', 'external_id' => 'kvk-1', 'chamber_of_commerce' => '11223344'],
            ]))
            ->assertStatus(201);

        MockClient::global()->assertNotSent(CreateAccount::class);
        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateSalesEntry && $request->body()->all()['Customer'] === 'kvk-guid');

        $this->assertDatabaseHas('connection_accounting_refs', [
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'kvk-1',
            'native_id' => 'kvk-guid',
        ]);
        $ref = ConnectionAccountingRef::query()->where('connection_id', $connection->getKey())->where('code', 'kvk-1')->first();
        $this->assertSame(['matched_on' => 'kvk'], $ref->attrs);
    }

    public function test_matches_relation_by_vat_number(): void
    {
        MockClient::global([
            GetRelations::class => MockResponse::make(['d' => ['results' => [[
                'ID' => 'vat-guid', 'Code' => 'V001', 'Name' => 'BTW Klant BV', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C',
                'ChamberOfCommerce' => '', 'VATNumber' => 'NL000099998B57',
            ]]]], 200),
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
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
                'party' => ['role' => 'debtor', 'name' => 'BTW Klant BV', 'kind' => 'company', 'external_id' => 'vat-1', 'vat_number' => 'NL000099998B57'],
            ]))
            ->assertStatus(201);

        MockClient::global()->assertNotSent(CreateAccount::class);
        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateSalesEntry && $request->body()->all()['Customer'] === 'vat-guid');

        $ref = ConnectionAccountingRef::query()->where('connection_id', $connection->getKey())->where('code', 'vat-1')->first();
        $this->assertSame(['matched_on' => 'vat'], $ref->attrs);
    }

    public function test_a_deleted_relation_is_relinked_instead_of_failing_the_booking(): void
    {
        MockClient::global([
            GetRelations::class => function (PendingRequest $pendingRequest) {
                $filter = (string) $pendingRequest->query()->get('$filter');

                if (str_contains($filter, 'ID eq')) {
                    return MockResponse::make(['d' => ['results' => []]], 200);
                }

                return MockResponse::make(['d' => ['results' => [[
                    'ID' => 'nieuwe-guid', 'Code' => 'N001', 'Name' => 'Acme BV', 'IsSales' => true, 'IsSupplier' => false,
                    'Status' => 'C', 'ChamberOfCommerce' => '12345678', 'VATNumber' => '',
                ]]]], 200);
            },
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
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
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'acme-1',
            'native_id' => 'verwijderde-guid',
        ]);

        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'kind' => 'company', 'external_id' => 'acme-1', 'chamber_of_commerce' => '12345678'],
            ]))
            ->assertStatus(201);

        $this->assertSame('relation.relinked', $response->json('warnings.0.code'));
        $this->assertSame('verwijderde-guid', $response->json('warnings.0.context.previous_relation_id'));

        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateSalesEntry
            && ($request->body()->all()['Customer'] ?? null) === 'nieuwe-guid');

        $this->assertDatabaseHas('connection_accounting_refs', [
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'acme-1',
            'native_id' => 'nieuwe-guid',
        ]);
    }

    public function test_an_unreadable_relation_keeps_its_link(): void
    {
        MockClient::global([
            GetRelations::class => MockResponse::make(['error' => 'boom'], 500),
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
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
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_RELATION,
            'code' => 'acme-1',
            'native_id' => 'bestaande-guid',
        ]);

        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'kind' => 'company', 'external_id' => 'acme-1', 'chamber_of_commerce' => '12345678'],
            ]))
            ->assertStatus(201);

        MockClient::global()->assertNotSent(CreateAccount::class);
        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateSalesEntry
            && ($request->body()->all()['Customer'] ?? null) === 'bestaande-guid');
    }

    public function test_matches_relation_by_name_and_writes_back_the_missing_chamber_of_commerce(): void
    {
        MockClient::global([
            GetRelations::class => function (PendingRequest $pendingRequest) {
                $filter = (string) $pendingRequest->query()->get('$filter');

                if ($filter !== '') {
                    return MockResponse::make(['d' => ['results' => []]], 200);
                }

                return MockResponse::make(['d' => ['results' => [[
                    'ID' => 'name-guid', 'Code' => 'N001', 'Name' => 'Acme BV', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C',
                    'ChamberOfCommerce' => '', 'VATNumber' => '',
                ]]]], 200);
            },
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
            UpdateAccount::class => MockResponse::make([], 204),
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

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'kind' => 'company', 'external_id' => 'name-1', 'chamber_of_commerce' => '12345678'],
            ]))
            ->assertStatus(201);

        $this->assertSame('relation.matched_by_name', $response->json('warnings.0.code'));
        $this->assertSame('name-guid', $response->json('warnings.0.context.relation_id'));

        MockClient::global()->assertNotSent(CreateAccount::class);
        MockClient::global()->assertSent(function ($request): bool {
            if (! $request instanceof UpdateAccount) {
                return false;
            }

            $body = $request->body()->all();

            return $request->resolveEndpoint() === "/crm/Accounts(guid'name-guid')"
                && ($body['ChamberOfCommerce'] ?? null) === '12345678'
                && ! array_key_exists('VATNumber', $body);
        });

        $ref = ConnectionAccountingRef::query()->where('connection_id', $connection->getKey())->where('code', 'name-1')->first();
        $this->assertSame(['matched_on' => 'name'], $ref->attrs);
    }

    public function test_ambiguous_chamber_of_commerce_match_returns_409_with_candidates(): void
    {
        MockClient::global([
            GetRelations::class => MockResponse::make(['d' => ['results' => [
                ['ID' => 'guid-amb-1', 'Code' => 'A001', 'Name' => 'Dubbel BV', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C', 'ChamberOfCommerce' => '99999999', 'VATNumber' => ''],
                ['ID' => 'guid-amb-2', 'Code' => 'A002', 'Name' => 'Dubbel BV Filiaal', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C', 'ChamberOfCommerce' => '99999999', 'VATNumber' => ''],
            ]]], 200),
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

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload([
                'party' => ['role' => 'debtor', 'name' => 'Dubbel BV', 'kind' => 'company', 'external_id' => 'dubbel-1', 'chamber_of_commerce' => '99999999'],
            ]))
            ->assertStatus(409)
            ->assertJsonPath('error', 'relation_ambiguous')
            ->assertJsonPath('status', 'failed');

        $this->assertEqualsCanonicalizing(['guid-amb-1', 'guid-amb-2'], array_column($response->json('candidates'), 'id'));

        MockClient::global()->assertNotSent(CreateAccount::class);
        MockClient::global()->assertNotSent(CreateSalesEntry::class);

        $this->assertDatabaseCount('connection_accounting_refs', 1);
    }

    public function test_relation_id_pins_and_skips_the_ladder(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-1']], 201),
            GetRelations::class => MockResponse::make(['d' => ['results' => [[
                'ID' => 'pinned-guid', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C',
            ]]]], 200),
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
                'party' => ['role' => 'debtor', 'name' => 'Genegeerde Naam', 'kind' => 'person', 'external_id' => 'pin-1', 'relation_id' => 'pinned-guid'],
            ]))
            ->assertStatus(201);

        MockClient::global()->assertNotSent(CreateAccount::class);
        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateSalesEntry && $request->body()->all()['Customer'] === 'pinned-guid');

        $ref = ConnectionAccountingRef::query()
            ->where('connection_id', $connection->getKey())
            ->where('kind', ConnectionAccountingRef::KIND_RELATION)
            ->where('code', 'pin-1')
            ->first();

        $this->assertSame('pinned-guid', $ref->native_id);
        $this->assertSame(['matched_on' => 'pinned'], $ref->attrs);
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

    public function test_canonical_accounting_write_ability_is_accepted(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-1']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::ACCOUNTING_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(201)
            ->assertJsonPath('external_ref', 'inv-guid-1');
    }

    public function test_canonical_read_ability_alone_cannot_write(): void
    {
        [$consumer] = $this->consumerWithExactConnection();
        $token = $consumer->createToken('t', [TokenAbilities::ACCOUNTING_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $this->salesInvoicePayload())
            ->assertStatus(403)
            ->assertJson(['error' => 'insufficient_ability']);
    }

    public function test_unconfigured_reference_mapping_returns_422(): void
    {
        MockClient::global([
            CreateAccount::class => MockResponse::make(['d' => ['ID' => 'auto-guid']], 201),
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

        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateDocument
            && $request->resolveEndpoint() === '/documents/Documents'
            && $request->body()->all()['Type'] === 10
            && $request->body()->all()['Account'] === 'cust-guid'
            && $request->body()->all()['FinancialTransactionEntryID'] === 'inv-guid-1'
            && $request->body()->all()['DocumentDate'] === '2026-06-16');

        MockClient::global()->assertSent(fn ($request): bool => $request instanceof CreateDocumentAttachment
            && $request->body()->all()['Document'] === 'doc-guid-1'
            && $request->body()->all()['FileName'] === 'factuur.pdf'
            && $request->body()->all()['Attachment'] === 'JVBERi0xLjQK');
    }

    public function test_purchase_attachment_reuses_exacts_auto_document(): void
    {
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
                'party' => ['role' => 'creditor', 'name' => 'Leverancier BV', 'kind' => 'company', 'external_id' => 'sup-1', 'chamber_of_commerce' => '87654321'],
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
