<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Accounting;

use App\Accounting\Enums\DocumentType;
use App\Accounting\Enums\TaxTreatment;
use App\Accounting\Exact\Contracts\ExactReferenceResolver;
use App\Accounting\Party;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\IdempotencyKey;
use App\Models\ProviderEntityLink;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Http\Request\Write\CreateSalesEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

/**
 * De tweede verdedigingslijn tegen dubbele boekingen. De idempotency-key dekt de
 * retry binnen zijn levensduur; deze tabel dekt de retry daarna — en de retry die
 * met een nieuwe key binnenkomt.
 */
class ProviderEntityLinkTest extends TestCase
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
                return 'cust-guid';
            }

            public function vatCode(float $taxRate, TaxTreatment $treatment, Connection $connection): string
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
     * @return array{0: Consumer, 1: Connection}
     */
    private function consumerWithExactConnection(string $accountExternalId = 'school1'): array
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create([
            'external_id' => $accountExternalId,
            'display_name' => 'School',
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
            'external_id' => 'INV-2026-001',
            'number' => '2026-001',
            'issue_date' => '2026-06-16',
            'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'vat_number' => 'NL000099998B57'],
            'lines' => [
                ['description' => 'Consultancy', 'amount' => 200, 'tax_rate' => 21, 'category' => 'omzet'],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postDocument(Consumer $consumer, array $payload, ?string $idempotencyKey = null, string $accountExternalId = 'school1'): TestResponse
    {
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', $accountExternalId)
            ->withHeader('Idempotency-Key', $idempotencyKey ?? (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $payload);
    }

    public function test_successful_push_records_the_link(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-1', 'EntryNumber' => 77]], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer, $connection] = $this->consumerWithExactConnection();

        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(201);

        $link = ProviderEntityLink::query()->sole();

        $this->assertSame((int) $connection->id, (int) $link->connection_id);
        $this->assertSame('exact', $link->provider);
        $this->assertSame(ProviderEntityLink::ENTITY_FINANCIAL_DOCUMENT, $link->entity_type);
        $this->assertSame('INV-2026-001', $link->external_id);
        $this->assertSame('inv-guid-1', $link->provider_entity_id);
        $this->assertSame('77', $link->provider_entity_number);
        $this->assertSame(ProviderEntityLink::ORIGIN_HUB, $link->origin);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $link->payload_fingerprint);
        $this->assertNotNull($link->last_synced_at);
    }

    public function test_failed_push_records_no_link(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['error' => ['message' => ['value' => 'stuk']]], 500),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();

        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(422);

        $this->assertDatabaseCount('provider_entity_links', 0);
    }

    /**
     * Dit is de kern van de fase. Zonder deze tabel boekt de tweede POST opnieuw
     * omdat de idempotency-key hem niet meer kent.
     */
    public function test_repost_after_key_loss_is_deduplicated_not_rebooked(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-2', 'EntryNumber' => 88]], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();

        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(201);

        // Simuleer verval/opruiming van de idempotency-key.
        IdempotencyKey::query()->delete();

        $second = $this->postDocument($consumer, $this->salesInvoicePayload());

        $second->assertStatus(200)
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('deduplicated', true)
            ->assertJsonPath('external_ref', 'inv-guid-2')
            ->assertJsonPath('external_number', '88');

        MockClient::global()->assertSentCount(1);
        $this->assertDatabaseCount('provider_entity_links', 1);
    }

    public function test_repost_with_changed_content_is_rejected_with_409(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-3']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();

        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(201);
        IdempotencyKey::query()->delete();

        $changed = $this->salesInvoicePayload(['lines' => [
            ['description' => 'Consultancy', 'amount' => 999, 'tax_rate' => 21, 'category' => 'omzet'],
        ]]);

        $this->postDocument($consumer, $changed)
            ->assertStatus(409)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('error', 'document_already_posted')
            ->assertJsonPath('external_ref', 'inv-guid-3');

        MockClient::global()->assertSentCount(1);
    }

    /**
     * Twee administraties mogen hetzelfde factuurnummer voeren. De dedupe-sleutel
     * bevat de connectie, dus dit is geen duplicaat.
     */
    public function test_dedupe_is_scoped_per_connection(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-4']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection('school1');
        $secondAccount = $consumer->accounts()->create(['external_id' => 'school2', 'display_name' => 'School 2']);
        Connection::factory()->forExact()->create([
            'account_id' => $secondAccount->id,
            'status' => 'active',
            'expires_at' => now()->addSeconds(600),
        ]);

        $this->postDocument($consumer, $this->salesInvoicePayload(), accountExternalId: 'school1')->assertStatus(201);
        $this->postDocument($consumer, $this->salesInvoicePayload(), accountExternalId: 'school2')->assertStatus(201);

        MockClient::global()->assertSentCount(2);
        $this->assertDatabaseCount('provider_entity_links', 2);
    }

    /**
     * Een dedupe die niet in de audit landt, leest in ops als "er gebeurde niets".
     */
    public function test_a_deduplicated_call_is_audited(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-5']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();

        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(201);
        IdempotencyKey::query()->delete();
        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(200);

        $this->assertDatabaseHas('pass_through_calls', [
            'path' => '/v1/accounting/documents',
            'status' => 200,
            'upstream_error' => 'deduplicated',
        ]);
    }

    public function test_a_rejected_repost_is_audited(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-6']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();

        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(201);
        IdempotencyKey::query()->delete();
        $this->postDocument($consumer, $this->salesInvoicePayload(['number' => '2026-999']))->assertStatus(409);

        $this->assertDatabaseHas('pass_through_calls', [
            'path' => '/v1/accounting/documents',
            'status' => 409,
            'upstream_error' => 'already_posted',
        ]);
    }
}
