<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Accounting;

use App\Accounting\DocumentFingerprint;
use App\Accounting\FinancialDocument;
use App\Accounting\ProviderEntityLinkRecorder;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\IdempotencyKey;
use App\Models\ProviderEntityLink;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Http\Request\Read\GetSalesEntries;
use Emeq\ExactApi\Http\Request\Write\CreatePurchaseEntry;
use Emeq\ExactApi\Http\Request\Write\CreateSalesEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Concerns\BindsFakeAccountingReferences;
use Tests\TestCase;

class ProviderEntityLinkTest extends TestCase
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

    /** @return array{0: Consumer, 1: Connection} */
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
            'party' => ['role' => 'debtor', 'name' => 'Acme BV', 'kind' => 'company', 'external_id' => 'acme-1', 'vat_number' => 'NL000099998B57'],
            'lines' => [
                ['description' => 'Consultancy', 'amount' => 200, 'tax_rate' => 21, 'category' => 'omzet'],
            ],
        ], $overrides);
    }

    /** @param  array<string, mixed>  $payload */
    private function postDocument(Consumer $consumer, array $payload, ?string $idempotencyKey = null, string $accountExternalId = 'school1'): TestResponse
    {
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', $accountExternalId)
            ->withHeader('Idempotency-Key', $idempotencyKey ?? (string) Str::uuid())
            ->postJson('/v1/accounting/documents', $payload);
    }

    public function test_a_sales_and_a_purchase_invoice_may_share_an_external_id(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'sales-guid']], 201),
            CreatePurchaseEntry::class => MockResponse::make(['d' => ['ID' => 'purchase-guid']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();

        $this->postDocument($consumer, $this->salesInvoicePayload())
            ->assertStatus(201)
            ->assertJsonPath('external_ref', 'sales-guid');

        $this->postDocument($consumer, $this->salesInvoicePayload([
            'type' => 'purchase_invoice',
            'party' => ['role' => 'creditor', 'name' => 'Leverancier BV', 'kind' => 'company', 'external_id' => 'leverancier-1', 'vat_number' => 'NL000099998B57'],
            'lines' => [
                ['description' => 'Inkoop', 'amount' => 200, 'tax_rate' => 21, 'category' => 'inkoop'],
            ],
        ]))
            ->assertStatus(201)
            ->assertJsonPath('external_ref', 'purchase-guid');

        $this->assertSame(2, ProviderEntityLink::query()->count());
        $this->assertEqualsCanonicalizing(
            ['sales_invoice', 'purchase_invoice'],
            ProviderEntityLink::query()->pluck('entity_subtype')->all(),
        );
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

    public function test_repost_after_key_loss_is_deduplicated_not_rebooked(): void
    {
        MockClient::global([
            GetSalesEntries::class => MockResponse::make(['d' => ['results' => [['EntryID' => '11111111-1111-4111-8111-111111111111']]]], 200),
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => '11111111-1111-4111-8111-111111111111', 'EntryNumber' => 88]], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();

        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(201);

        IdempotencyKey::query()->delete();

        $second = $this->postDocument($consumer, $this->salesInvoicePayload());

        $second->assertStatus(200)
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('deduplicated', true)
            ->assertJsonPath('external_ref', '11111111-1111-4111-8111-111111111111')
            ->assertJsonPath('external_number', '88');

        MockClient::global()->assertSentCount(2);
        $this->assertDatabaseCount('provider_entity_links', 1);
    }

    public function test_repost_after_the_bookkeeping_deleted_the_entry_books_it_again(): void
    {
        MockClient::global([
            GetSalesEntries::class => MockResponse::make(['d' => ['results' => []]], 200),
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => '33333333-3333-4333-8333-333333333333', 'EntryNumber' => 91]], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();

        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(201);

        IdempotencyKey::query()->delete();

        $this->postDocument($consumer, $this->salesInvoicePayload())
            ->assertStatus(201)
            ->assertJsonPath('external_ref', '33333333-3333-4333-8333-333333333333');

        MockClient::global()->assertSentCount(3);
        $this->assertDatabaseCount('provider_entity_links', 1);
    }

    public function test_repost_stays_deduplicated_when_the_bookkeeping_cannot_answer(): void
    {
        MockClient::global([
            GetSalesEntries::class => MockResponse::make(['error' => ['message' => ['value' => 'stuk']]], 500),
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => '44444444-4444-4444-8444-444444444444', 'EntryNumber' => 44]], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();

        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(201);

        IdempotencyKey::query()->delete();

        $this->postDocument($consumer, $this->salesInvoicePayload())
            ->assertStatus(200)
            ->assertJsonPath('deduplicated', true);

        $this->assertDatabaseCount('provider_entity_links', 1);
    }

    public function test_repost_with_changed_content_is_rejected_with_409(): void
    {
        MockClient::global([
            GetSalesEntries::class => MockResponse::make(['d' => ['results' => [['EntryID' => '22222222-2222-4222-8222-222222222222']]]], 200),
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => '22222222-2222-4222-8222-222222222222']], 201),
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
            ->assertJsonPath('external_ref', '22222222-2222-4222-8222-222222222222');

        MockClient::global()->assertSentCount(2);
    }

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
            'administratie_id' => '9990001',
        ]);

        $this->postDocument($consumer, $this->salesInvoicePayload(), accountExternalId: 'school1')->assertStatus(201);
        $this->postDocument($consumer, $this->salesInvoicePayload(), accountExternalId: 'school2')->assertStatus(201);

        MockClient::global()->assertSentCount(2);
        $this->assertDatabaseCount('provider_entity_links', 2);
    }

    public function test_a_second_tenant_cannot_rebook_one_document_into_one_administration(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-shared']], 201),
        ]);
        $this->bindFakeReferences();

        [$accountant] = $this->consumerWithExactConnection('school1');
        [$entrepreneur] = $this->consumerWithExactConnection('school1');

        $this->postDocument($accountant, $this->salesInvoicePayload())->assertStatus(201);

        $response = $this->postDocument($entrepreneur, $this->salesInvoicePayload())
            ->assertStatus(409)
            ->assertJsonPath('error', 'document_already_posted');

        MockClient::global()->assertSentCount(1);
        $this->assertDatabaseCount('provider_entity_links', 1);

        $body = $response->json();
        $this->assertArrayNotHasKey('external_ref', $body);
        $this->assertArrayNotHasKey('external_number', $body);
        $this->assertStringNotContainsString('inv-guid-shared', $response->getContent());
    }

    public function test_a_different_document_sharing_a_number_still_books(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-other']], 201),
        ]);
        $this->bindFakeReferences();

        [$accountant] = $this->consumerWithExactConnection('school1');
        [$entrepreneur] = $this->consumerWithExactConnection('school1');

        $this->postDocument($accountant, $this->salesInvoicePayload())->assertStatus(201);

        $different = $this->salesInvoicePayload([
            'lines' => [
                ['description' => 'Iets heel anders', 'amount' => 999, 'tax_rate' => 21, 'category' => 'omzet'],
            ],
        ]);

        $this->postDocument($entrepreneur, $different)->assertStatus(201);

        MockClient::global()->assertSentCount(2);
        $this->assertDatabaseCount('provider_entity_links', 2);
    }

    public function test_an_empty_administration_id_disables_the_cross_connection_guard(): void
    {
        [, $first] = $this->consumerWithExactConnection('school1');
        [, $second] = $this->consumerWithExactConnection('school1');

        $first->forceFill(['administratie_id' => null])->save();
        $second->forceFill(['administratie_id' => null])->save();

        $document = FinancialDocument::fromArray($this->salesInvoicePayload());
        $fingerprint = DocumentFingerprint::for($document);

        ProviderEntityLink::query()->create([
            'connection_id' => $first->getKey(),
            'entity_type' => ProviderEntityLink::ENTITY_FINANCIAL_DOCUMENT,
            'entity_subtype' => 'sales_invoice',
            'external_id' => $document->externalId,
            'provider' => 'exact',
            'administratie_id' => '',
            'provider_entity_id' => 'inv-guid-blank',
            'payload_fingerprint' => $fingerprint,
            'origin' => ProviderEntityLink::ORIGIN_HUB,
            'last_synced_at' => now(),
        ]);

        $recorder = app(ProviderEntityLinkRecorder::class);

        $this->assertNull($recorder->findPostedOnSameAdministration($second, $document, $fingerprint));

        $second->forceFill(['administratie_id' => '4471372'])->save();
        ProviderEntityLink::query()->where('connection_id', $first->getKey())->update(['administratie_id' => '4471372']);

        $this->assertNotNull($recorder->findPostedOnSameAdministration($second, $document, $fingerprint));
    }

    public function test_a_simultaneous_push_from_another_connection_on_one_administration_is_refused(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-guid-race']], 201),
        ]);
        $this->bindFakeReferences();

        [, $accountantConnection] = $this->consumerWithExactConnection('school1');
        [$entrepreneur] = $this->consumerWithExactConnection('school1');

        $document = FinancialDocument::fromArray($this->salesInvoicePayload());
        $held = app(ProviderEntityLinkRecorder::class)->administrationLock(
            $accountantConnection,
            $document,
            DocumentFingerprint::for($document),
        );

        $this->assertNotNull($held);
        $this->assertTrue($held->get());

        $response = $this->postDocument($entrepreneur, $this->salesInvoicePayload())
            ->assertStatus(409)
            ->assertJsonPath('error', 'document_sync_in_progress');

        $this->assertSame(
            (string) IdempotencyKey::retryAfterCeilingSeconds(),
            $response->headers->get('Retry-After'),
        );

        MockClient::global()->assertNothingSent();
        $this->assertDatabaseCount('provider_entity_links', 0);
    }

    public function test_a_failed_push_releases_the_cross_connection_lock(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['error' => ['message' => 'boom']], 500),
        ]);
        $this->bindFakeReferences();

        [$consumer, $connection] = $this->consumerWithExactConnection('school1');

        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(502);

        $document = FinancialDocument::fromArray($this->salesInvoicePayload());
        $lock = app(ProviderEntityLinkRecorder::class)->administrationLock(
            $connection,
            $document,
            DocumentFingerprint::for($document),
        );

        $this->assertNotNull($lock);
        $this->assertTrue($lock->get(), 'De grendel staat na een mislukte push nog vast.');
    }

    public function test_a_connection_without_an_administration_id_has_no_cross_connection_lock(): void
    {
        [, $connection] = $this->consumerWithExactConnection('school1');
        $connection->forceFill(['administratie_id' => null])->save();

        $document = FinancialDocument::fromArray($this->salesInvoicePayload());

        $this->assertNull(app(ProviderEntityLinkRecorder::class)->administrationLock(
            $connection,
            $document,
            DocumentFingerprint::for($document),
        ));
    }

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

    public function test_a_booking_that_landed_despite_a_timeout_is_recovered(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make([], 504),
            GetSalesEntries::class => MockResponse::make(['d' => ['results' => [[
                'EntryID' => 'recovered-guid',
                'EntryNumber' => 91,
                'SalesEntryLines' => ['results' => []],
            ]]]], 200),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();

        $this->postDocument($consumer, $this->salesInvoicePayload())
            ->assertStatus(200)
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('recovered', true)
            ->assertJsonPath('external_ref', 'recovered-guid');

        $this->assertDatabaseHas('provider_entity_links', [
            'external_id' => 'INV-2026-001',
            'provider_entity_id' => 'recovered-guid',
        ]);

        $this->assertDatabaseHas('pass_through_calls', ['upstream_error' => 'recovered_after_timeout']);
    }

    public function test_a_timeout_without_a_landed_booking_still_reports_the_failure(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make([], 504),
            GetSalesEntries::class => MockResponse::make(['d' => ['results' => []]], 200),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();

        $response = $this->postDocument($consumer, $this->salesInvoicePayload());

        $this->assertGreaterThanOrEqual(500, $response->status());
        $response->assertJsonPath('status', 'failed');

        $this->assertDatabaseCount('provider_entity_links', 0);
    }

    public function test_a_uuid_key_is_not_probed_because_yourref_cannot_carry_it(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make([], 504),
            GetSalesEntries::class => MockResponse::make(['d' => ['results' => [[
                'EntryID' => 'andere-relatie-guid',
                'EntryNumber' => 91,
                'SalesEntryLines' => ['results' => []],
            ]]]], 200),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();

        $payload = $this->salesInvoicePayload();
        $payload['external_id'] = '877f9972-3969-4d9c-9405-356a18072bed';

        $response = $this->postDocument($consumer, $payload);

        $this->assertGreaterThanOrEqual(500, $response->status());
        $response->assertJsonPath('status', 'failed');

        $this->assertDatabaseCount('provider_entity_links', 0);
    }

    public function test_a_functional_rejection_is_not_probed(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['error' => ['message' => ['value' => 'ongeldig btw-nummer']]], 500),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();

        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(422);

        MockClient::global()->assertNotSent(GetSalesEntries::class);
    }

    public function test_a_concurrent_attempt_with_a_different_key_is_refused(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-race']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer, $connection] = $this->consumerWithExactConnection();

        ProviderEntityLink::query()->create([
            'connection_id' => $connection->getKey(),
            'entity_type' => ProviderEntityLink::ENTITY_FINANCIAL_DOCUMENT,
            'entity_subtype' => 'sales_invoice',
            'external_id' => 'INV-2026-001',
            'provider' => 'exact',
            'provider_entity_id' => null,
            'origin' => ProviderEntityLink::ORIGIN_HUB,
            'last_synced_at' => now(),
        ]);

        $this->postDocument($consumer, $this->salesInvoicePayload())
            ->assertStatus(409)
            ->assertJsonPath('error', 'document_sync_in_progress');

        MockClient::global()->assertNothingSent();
    }

    public function test_a_concurrent_attempt_carries_a_retry_after_header(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-race']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer, $connection] = $this->consumerWithExactConnection();

        $lockedAt = now()->subSeconds(100);
        ProviderEntityLink::query()->create([
            'connection_id' => $connection->getKey(),
            'entity_type' => ProviderEntityLink::ENTITY_FINANCIAL_DOCUMENT,
            'entity_subtype' => 'sales_invoice',
            'external_id' => 'INV-2026-001',
            'provider' => 'exact',
            'provider_entity_id' => null,
            'origin' => ProviderEntityLink::ORIGIN_HUB,
            'last_synced_at' => $lockedAt,
        ]);

        $response = $this->postDocument($consumer, $this->salesInvoicePayload())
            ->assertStatus(409)
            ->assertJsonPath('error', 'document_sync_in_progress');

        $this->assertSame(
            (string) IdempotencyKey::retryAfterCeilingSeconds(),
            $response->headers->get('Retry-After'),
        );

        MockClient::global()->assertNothingSent();
    }

    public function test_retry_after_never_points_past_the_end_of_the_claim(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-race']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer, $connection] = $this->consumerWithExactConnection();

        ProviderEntityLink::query()->create([
            'connection_id' => $connection->getKey(),
            'entity_type' => ProviderEntityLink::ENTITY_FINANCIAL_DOCUMENT,
            'entity_subtype' => 'sales_invoice',
            'external_id' => 'INV-2026-001',
            'provider' => 'exact',
            'provider_entity_id' => null,
            'origin' => ProviderEntityLink::ORIGIN_HUB,
            'last_synced_at' => now()->subSeconds(IdempotencyKey::leaseSeconds() - 3),
        ]);

        $response = $this->postDocument($consumer, $this->salesInvoicePayload())
            ->assertStatus(409)
            ->assertJsonPath('error', 'document_sync_in_progress');

        $this->assertSame('3', $response->headers->get('Retry-After'));
    }

    public function test_a_stale_claim_is_taken_over(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-takeover']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer, $connection] = $this->consumerWithExactConnection();

        ProviderEntityLink::query()->create([
            'connection_id' => $connection->getKey(),
            'entity_type' => ProviderEntityLink::ENTITY_FINANCIAL_DOCUMENT,
            'entity_subtype' => 'sales_invoice',
            'external_id' => 'INV-2026-001',
            'provider' => 'exact',
            'provider_entity_id' => null,
            'origin' => ProviderEntityLink::ORIGIN_HUB,
            'last_synced_at' => now()->subSeconds(IdempotencyKey::leaseSeconds() + 60),
        ]);

        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(201);

        $this->assertSame('inv-takeover', ProviderEntityLink::query()->sole()->provider_entity_id);
    }

    public function test_a_failed_attempt_releases_the_claim(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['error' => ['message' => ['value' => 'stuk']]], 500),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();

        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(422);
        $this->assertDatabaseCount('provider_entity_links', 0);

        MockClient::destroyGlobal();
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-retry']], 201),
        ]);

        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(201);
    }

    public function test_a_mapping_failure_also_releases_the_claim(): void
    {
        $this->bindFakeReferences();

        [$consumer, $connection] = $this->consumerWithExactConnection();
        $connection->update(['administratie_id' => null]);

        $this->postDocument($consumer, $this->salesInvoicePayload())
            ->assertStatus(422)
            ->assertJsonPath('error', 'mapping_failed');

        $this->assertDatabaseCount('provider_entity_links', 0);
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
