<?php

namespace Tests\Feature\Accounting\Validation\Enrichment;

use App\Accounting\BookingWarnings;
use App\Accounting\Validation\Severity;
use App\Integrations\Exact\Accounting\ConnectionMappingExactReferenceResolver;
use App\Integrations\Exact\Accounting\ExactRelationResolver;
use App\Integrations\Exact\Accounting\ExactReportEnricher;
use App\Integrations\Exact\ExactReferenceData;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use Emeq\ExactApi\Http\Request\RawExactRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

/**
 * Een datum buiten elke boekperiode wordt door Exact geweigerd met "Verplicht: Boekjaar".
 * Zonder deze finding meldde validate `valid: true` voor zo'n document. Vereist DB.
 */
class ExactReportEnricherPeriodTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR_2026_START_MS = 1767225600000;

    private const YEAR_2026_END_MS = 1798675200000;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

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

    private function enricher(): ExactReportEnricher
    {
        return new ExactReportEnricher(new ConnectionMappingExactReferenceResolver(new ExactRelationResolver(new BookingWarnings)));
    }

    private function connection(): Connection
    {
        $account = Account::factory()->for(Consumer::factory()->create())->create();

        return Connection::factory()->forExact()->for($account)->create();
    }

    private function mockPeriods(MockResponse $response): void
    {
        MockClient::destroyGlobal();
        MockClient::global([RawExactRequest::class => $response]);
    }

    private function mockOpenYear2026(): void
    {
        $this->mockPeriods(MockResponse::make(['d' => ['results' => [[
            'FinYear' => 2026,
            'FinPeriod' => 1,
            'StartDate' => '/Date('.self::YEAR_2026_START_MS.')/',
            'EndDate' => '/Date('.self::YEAR_2026_END_MS.')/',
        ]]]], 200));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge(['issue_date' => '2026-05-21', 'lines' => []], $overrides);
    }

    public function test_a_date_outside_every_period_is_reported_as_blocking(): void
    {
        $this->mockOpenYear2026();

        $findings = $this->enricher()->enrich(
            $this->payload(['issue_date' => '2025-10-15']),
            $this->connection(),
        );

        $this->assertCount(1, $findings);
        $this->assertSame('exact.period.closed', $findings[0]->code);
        $this->assertSame(Severity::Warning, $findings[0]->severity);
        $this->assertTrue($findings[0]->blocking);
        $this->assertSame('issue_date', $findings[0]->path);
        $this->assertSame('2025-10-15', $findings[0]->current);
        $this->assertStringContainsString('01-01-2026', $findings[0]->message);
        $this->assertStringContainsString('31-12-2026', $findings[0]->message);
    }

    public function test_a_date_inside_a_period_produces_no_finding(): void
    {
        $this->mockOpenYear2026();

        $findings = $this->enricher()->enrich($this->payload(), $this->connection());

        $this->assertSame([], $findings);
    }

    public function test_the_first_and_last_day_of_a_period_are_inside_it(): void
    {
        $this->mockOpenYear2026();
        $connection = $this->connection();

        foreach (['2026-01-01', '2026-12-31'] as $date) {
            $this->assertSame(
                [],
                $this->enricher()->enrich($this->payload(['issue_date' => $date]), $connection),
                "expected {$date} to be inside the period",
            );
        }
    }

    public function test_an_unreadable_period_list_produces_no_finding(): void
    {
        $this->mockPeriods(MockResponse::make(['error' => 'boom'], 500));

        $findings = $this->enricher()->enrich(
            $this->payload(['issue_date' => '2021-03-09']),
            $this->connection(),
        );

        $this->assertSame([], $findings);
    }

    public function test_a_connection_without_a_division_is_not_asked(): void
    {
        $this->mockOpenYear2026();

        $connection = $this->connection();
        $connection->administratie_id = '';
        $connection->save();

        $findings = $this->enricher()->enrich(
            $this->payload(['issue_date' => '2021-03-09']),
            $connection,
        );

        $this->assertSame([], $findings);
    }

    /**
     * De cache serialiseert wat erin gaat. Een `DateTimeImmutable` kwam op productie als
     * incomplete object terug en liet elke tweede validate met een 500 stranden; de
     * array-store in tests serialiseert niet en verborg dat. Vandaar de eis op de inhoud.
     */
    public function test_the_cached_period_list_holds_only_scalars(): void
    {
        $this->mockOpenYear2026();
        $connection = $this->connection();

        (new ExactReferenceData($connection))->financialPeriods();

        $cached = Cache::get("exact:financial-periods:{$connection->getKey()}:{$connection->administratie_id}");

        $this->assertIsArray($cached);
        $this->assertNotEmpty($cached);

        foreach ($cached as $row) {
            foreach ($row as $key => $value) {
                $this->assertTrue(
                    is_string($value) || is_int($value),
                    "cached period field {$key} must be a scalar, got ".get_debug_type($value),
                );
            }
        }
    }

    public function test_a_second_call_reads_the_cache_and_still_judges_the_date(): void
    {
        $this->mockOpenYear2026();
        $connection = $this->connection();

        $this->enricher()->enrich($this->payload(), $connection);

        $findings = $this->enricher()->enrich(
            $this->payload(['issue_date' => '2025-10-15']),
            $connection,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('exact.period.closed', $findings[0]->code);
        $this->assertStringContainsString('01-01-2026', $findings[0]->message);
    }

    public function test_a_document_without_a_date_is_left_to_the_agnostic_validator(): void
    {
        $this->mockOpenYear2026();

        $findings = $this->enricher()->enrich(['lines' => []], $this->connection());

        $this->assertSame([], $findings);
    }
}
