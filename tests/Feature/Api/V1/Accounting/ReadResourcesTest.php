<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Accounting;

use App\Accounting\AccountingResult;
use App\Accounting\AccountingTargetRegistry;
use App\Accounting\Contracts\AccountingTarget;
use App\Accounting\FinancialDocument;
use App\Enums\Provider;
use App\Models\Connection;
use App\Models\ConnectionAccountingRef;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Emeq\ExactApi\Http\Request\Read\GetRelations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

/**
 * Het provider-onafhankelijke lees-pad. De consumer vraagt canonieke resources op en
 * krijgt overal dezelfde vorm terug: `{data, next_cursor, has_more}`.
 */
class ReadResourcesTest extends TestCase
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

    /**
     * @return array{0: Consumer, 1: Connection}
     */
    private function connected(): array
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'School 1']);
        // Niet-verlopen token: anders vuurt de SDK een refresh die niet gemockt is en
        // krijg je een gemaskeerde 502 in plaats van het antwoord dat je test.
        $connection = Connection::factory()->forExact()->for($account)->create([
            'status' => 'active',
            'expires_at' => now()->addSeconds(600),
        ]);

        return [$consumer, $connection];
    }

    private function fetch(Consumer $consumer, string $path, array $query = []): TestResponse
    {
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->getJson('/v1/accounting/'.$path.($query === [] ? '' : '?'.http_build_query($query)));
    }

    private function seedMirror(Connection $connection, string $kind, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            ConnectionAccountingRef::query()->create([
                'connection_id' => $connection->getKey(),
                'kind' => $kind,
                'code' => str_pad((string) ($i * 100), 4, '0', STR_PAD_LEFT),
                'native_id' => "native-{$i}",
                'label' => "Rij {$i}",
                'attrs' => $kind === ConnectionAccountingRef::KIND_VAT ? ['percentage' => 21] : ['x' => $i],
            ]);
        }
    }

    public function test_ledger_accounts_come_from_the_mirror_in_canonical_shape(): void
    {
        [$consumer, $connection] = $this->connected();
        $this->seedMirror($connection, ConnectionAccountingRef::KIND_GL, 2);

        $this->fetch($consumer, 'ledger-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.code', '0100')
            ->assertJsonPath('data.0.id', 'native-1')
            ->assertJsonPath('data.0.name', 'Rij 1')
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_cursor', null);
    }

    /**
     * Geen partner-call: de mirror is de bron. Een lees-endpoint dat Exact belt voor
     * stabiele referentiedata is verspilling én een extra faalpunt.
     */
    public function test_reading_the_mirror_does_not_touch_the_partner(): void
    {
        [$consumer, $connection] = $this->connected();
        $this->seedMirror($connection, ConnectionAccountingRef::KIND_GL, 1);
        MockClient::global([]);

        $this->fetch($consumer, 'ledger-accounts')->assertOk();

        MockClient::global()->assertNothingSent();
    }

    public function test_tax_codes_expose_the_rate_as_a_number(): void
    {
        [$consumer, $connection] = $this->connected();
        $this->seedMirror($connection, ConnectionAccountingRef::KIND_VAT, 1);

        $response = $this->fetch($consumer, 'tax-codes')->assertOk();

        // JSON kent één getaltype; wat telt is dat het een getal is en geen string.
        $this->assertIsNumeric($response->json('data.0.rate'));
        $this->assertEqualsWithDelta(21.0, $response->json('data.0.rate'), 0.001);
    }

    /**
     * Zonder percentage blijft `rate` null — 0.0 zou "0%" betekenen, en dat bestaat ook.
     */
    public function test_a_tax_code_without_a_percentage_reports_a_null_rate(): void
    {
        [$consumer, $connection] = $this->connected();
        ConnectionAccountingRef::query()->create([
            'connection_id' => $connection->getKey(),
            'kind' => ConnectionAccountingRef::KIND_VAT,
            'code' => '99',
            'native_id' => 'vat-99',
            'label' => 'Onbekend',
            'attrs' => [],
        ]);

        $this->fetch($consumer, 'tax-codes')->assertOk()->assertJsonPath('data.0.rate', null);
    }

    public function test_paging_walks_the_full_set_without_repeats(): void
    {
        [$consumer, $connection] = $this->connected();
        $this->seedMirror($connection, ConnectionAccountingRef::KIND_GL, 5);

        $first = $this->fetch($consumer, 'ledger-accounts', ['limit' => 2]);
        $first->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('has_more', true);

        $second = $this->fetch($consumer, 'ledger-accounts', ['limit' => 2, 'cursor' => $first->json('next_cursor')]);
        $second->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('has_more', true);

        $third = $this->fetch($consumer, 'ledger-accounts', ['limit' => 2, 'cursor' => $second->json('next_cursor')]);
        $third->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('has_more', false);

        $codes = array_merge(
            array_column($first->json('data'), 'code'),
            array_column($second->json('data'), 'code'),
            array_column($third->json('data'), 'code'),
        );

        $this->assertSame(['0100', '0200', '0300', '0400', '0500'], $codes);
        $this->assertSame($codes, array_unique($codes));
    }

    public function test_an_out_of_range_limit_is_rejected(): void
    {
        [$consumer] = $this->connected();

        $this->fetch($consumer, 'ledger-accounts', ['limit' => 5000])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_query');
    }

    public function test_customers_are_fetched_live_and_filtered_on_role(): void
    {
        MockClient::global([
            GetRelations::class => MockResponse::make(['d' => ['results' => [
                ['ID' => 'rel-1', 'Code' => '  1001  ', 'Name' => 'Acme BV', 'VATNumber' => 'NL000099998B57', 'Email' => 'a@acme.test', 'IsSales' => true, 'IsSupplier' => false],
            ]]], 200),
        ]);
        [$consumer] = $this->connected();

        $this->fetch($consumer, 'customers')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'rel-1')
            ->assertJsonPath('data.0.name', 'Acme BV')
            // Exact vult Code op met spaties; die horen niet in het canonieke antwoord.
            ->assertJsonPath('data.0.code', '1001')
            ->assertJsonPath('data.0.roles', ['debtor']);

        MockClient::global()->assertSent(fn (GetRelations $request): bool => $request->query()->get('$filter') === 'IsSales eq true');
    }

    public function test_suppliers_use_the_supplier_filter(): void
    {
        MockClient::global([
            GetRelations::class => MockResponse::make(['d' => ['results' => [
                ['ID' => 'rel-2', 'Name' => 'Leverancier', 'IsSales' => false, 'IsSupplier' => true],
            ]]], 200),
        ]);
        [$consumer] = $this->connected();

        $this->fetch($consumer, 'suppliers')
            ->assertOk()
            ->assertJsonPath('data.0.roles', ['creditor']);

        MockClient::global()->assertSent(fn (GetRelations $request): bool => $request->query()->get('$filter') === 'IsSupplier eq true');
    }

    /**
     * De continuation-token van Exact wordt een ondoorzichtige cursor voor de consumer.
     */
    public function test_a_live_page_exposes_the_partner_continuation_token_as_a_cursor(): void
    {
        MockClient::global([
            GetRelations::class => MockResponse::make(['d' => [
                'results' => [['ID' => 'rel-1', 'Name' => 'Acme', 'IsSales' => true]],
                '__next' => 'https://start.exactonline.nl/api/v1/1/crm/Accounts?%24skiptoken=guid%27tok-1%27',
            ]], 200),
        ]);
        [$consumer] = $this->connected();

        $response = $this->fetch($consumer, 'customers');

        $response->assertOk()->assertJsonPath('has_more', true);
        $this->assertNotNull($response->json('next_cursor'));
        $this->assertStringNotContainsString('skiptoken', (string) $response->json('next_cursor'));
    }

    /**
     * Een lege lijst teruggeven terwijl de partner plat ligt is een leugen. Dit is het
     * verschil met ExactReferenceData, dat bewust fail-soft is voor de admin-UI.
     */
    public function test_an_upstream_failure_surfaces_instead_of_an_empty_list(): void
    {
        MockClient::global([
            GetRelations::class => MockResponse::make(['error' => ['message' => ['value' => 'kapot']]], 503),
        ]);
        [$consumer] = $this->connected();

        $response = $this->fetch($consumer, 'customers');

        $this->assertGreaterThanOrEqual(500, $response->status());
        $this->assertNull($response->json('data'));
    }

    /**
     * Bewijst dat de gate op de capability zit en niet op de providernaam.
     */
    public function test_a_provider_without_the_read_capability_gets_422(): void
    {
        app(AccountingTargetRegistry::class)->register(Provider::Snelstart->value, WriteOnlyReadTarget::class);

        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'School 1']);
        Connection::factory()->forSnelstart()->for($account)->create();

        $token = $consumer->createToken('t', [TokenAbilities::SNELSTART_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->getJson('/v1/accounting/ledger-accounts')
            ->assertStatus(422)
            ->assertJsonPath('error', 'unsupported_capability')
            ->assertJsonPath('category', 'UNSUPPORTED_CAPABILITY');
    }

    public function test_reading_requires_a_read_ability(): void
    {
        [$consumer] = $this->connected();
        $token = $consumer->createToken('t', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school1')
            ->getJson('/v1/accounting/ledger-accounts')
            ->assertStatus(403);
    }

    /**
     * De mirror van de ene koppeling mag nooit bij de andere opduiken.
     */
    public function test_the_mirror_is_scoped_to_the_connection(): void
    {
        [$consumer, $connection] = $this->connected();
        $this->seedMirror($connection, ConnectionAccountingRef::KIND_GL, 1);

        $otherAccount = $consumer->accounts()->create(['external_id' => 'school2', 'display_name' => 'School 2']);
        Connection::factory()->forExact()->for($otherAccount)->create();

        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school2')
            ->getJson('/v1/accounting/ledger-accounts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}

/** Adapter die kan boeken maar niets kan lezen. */
final class WriteOnlyReadTarget implements AccountingTarget
{
    public function push(FinancialDocument $document, Connection $connection): AccountingResult
    {
        return new AccountingResult(201, 'ref', null, [], []);
    }
}
