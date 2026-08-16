<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Accounting;

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

/**
 * De tweede verdedigingslijn tegen dubbele boekingen. De idempotency-key dekt de
 * retry binnen zijn levensduur; deze tabel dekt de retry daarna — en de retry die
 * met een nieuwe key binnenkomt.
 */
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

    /**
     * Verkoop- en inkoopnummering lopen bij een consumer los van elkaar, dus
     * external_id "INV-2026-001" kan allebei voorkomen. Zolang het documenttype
     * niet in de identiteitssleutel zat, maakte de eerste boeking de tweede
     * permanent onmogelijk: de claim botste, de fingerprint week af, en het
     * antwoord was 409 "gebruik een nieuw external_id".
     */
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
            'party' => ['role' => 'creditor', 'name' => 'Leverancier BV', 'vat_number' => 'NL000099998B57'],
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

    /**
     * Het laatste herboek-venster: de partner commit, maar de respons bereikt ons niet.
     * De Hub weet dan van niets en zou bij een retry opnieuw boeken. De probe vraagt na.
     */
    public function test_a_booking_that_landed_despite_a_timeout_is_recovered(): void
    {
        MockClient::global([
            // De boeking zelf krijgt geen antwoord…
            CreateSalesEntry::class => MockResponse::make([], 504),
            // …maar bij navraag staat hij er wél.
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

        // De link is alsnog vastgelegd, dus een volgende poging dedupliceert.
        $this->assertDatabaseHas('provider_entity_links', [
            'external_id' => 'INV-2026-001',
            'provider_entity_id' => 'recovered-guid',
        ]);

        $this->assertDatabaseHas('pass_through_calls', ['upstream_error' => 'recovered_after_timeout']);
    }

    /**
     * Vindt de probe niets, dan blijft de fout staan. Doen alsof het goed ging zou
     * erger zijn dan een terechte foutmelding.
     */
    public function test_a_timeout_without_a_landed_booking_still_reports_the_failure(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make([], 504),
            GetSalesEntries::class => MockResponse::make(['d' => ['results' => []]], 200),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();

        $response = $this->postDocument($consumer, $this->salesInvoicePayload());

        // Welke 5xx precies hangt af van hoe de partner faalt; wat telt is dat de fout
        // blijft staan en er niets is vastgelegd.
        $this->assertGreaterThanOrEqual(500, $response->status());
        $response->assertJsonPath('status', 'failed');

        $this->assertDatabaseCount('provider_entity_links', 0);
    }

    /**
     * Een functionele weigering (422) is een definitief antwoord — daar valt niets na
     * te vragen, en een probe zou alleen een nutteloze partner-call zijn.
     */
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

    /**
     * De idempotency-key beschermt alleen tegen retries mét dezelfde sleutel. Een client
     * die per poging een verse UUID genereert omzeilt die volledig — dan is de claim op
     * `external_id` het enige dat een dubbele boeking tegenhoudt.
     */
    public function test_a_concurrent_attempt_with_a_different_key_is_refused(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-race']], 201),
        ]);
        $this->bindFakeReferences();

        [$consumer, $connection] = $this->consumerWithExactConnection();

        // Deterministische stand-in voor de race: de claim van de eerste poging staat er
        // al, nog zonder partner-referentie.
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

    /**
     * Dezelfde instructie als de idempotency-laag bij "er loopt al iets" (409):
     * een consumer die op Retry-After pace't mag ook op déze 409 kunnen wachten
     * in plaats van te pollen.
     */
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

        // Niet het resterende lease-venster (hier 800s): dat beantwoordt "wanneer
        // verklaren we de andere poging dood?", niet "wanneer mag je terugkomen?".
        // Een normale boeking is in seconden klaar, dus het lease-venster als
        // Retry-After legt dit document een kwartier stil bij een consumer die de
        // header honoreert — en sinds hub-sdk 0.16.0 doet die dat.
        $this->assertSame(
            (string) IdempotencyKey::retryAfterCeilingSeconds(),
            $response->headers->get('Retry-After'),
        );

        MockClient::global()->assertNothingSent();
    }

    /**
     * Vlak voor het einde van de lease is het resterende venster kleiner dan het
     * plafond, en dan wint het venster: verder vooruit wijzen dan de claim zelf
     * bestaat heeft geen zin.
     */
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

    /**
     * Een claim van een gecrashte worker mag dit document niet voorgoed blokkeren.
     */
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

    /**
     * Eén storing mag dit external_id niet permanent blokkeren — geldt voor élke
     * faalgrond, ook een mapping-fout die niet eens bij de partner aankomt.
     */
    public function test_a_failed_attempt_releases_the_claim(): void
    {
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['error' => ['message' => ['value' => 'stuk']]], 500),
        ]);
        $this->bindFakeReferences();

        [$consumer] = $this->consumerWithExactConnection();

        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(422);
        $this->assertDatabaseCount('provider_entity_links', 0);

        // En dus mag een volgende poging gewoon boeken.
        MockClient::destroyGlobal();
        MockClient::global([
            CreateSalesEntry::class => MockResponse::make(['d' => ['ID' => 'inv-retry']], 201),
        ]);

        $this->postDocument($consumer, $this->salesInvoicePayload())->assertStatus(201);
    }

    /**
     * Een mapping-fout raakt de partner niet eens, maar liet de claim eerder wél staan.
     */
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
