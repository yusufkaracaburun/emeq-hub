<?php

namespace Tests\Feature\Exact;

use App\Integrations\Exact\ExactReferenceData;
use App\Models\Connection;
use App\Models\Consumer;
use Emeq\ExactApi\Http\Request\Read\GetGlAccounts;
use Emeq\ExactApi\Http\Request\Read\GetRelations;
use Emeq\ExactApi\Http\Request\Read\GetVatCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Tests\TestCase;

class ExactReferenceDataTest extends TestCase
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

    private function exactConnection(array $overrides = []): Connection
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);

        return Connection::factory()->forExact()->create(array_merge([
            'account_id' => $account->id,
            'status' => 'active',
            'expires_at' => now()->addSeconds(600),
        ], $overrides));
    }

    public function test_vat_codes_are_mapped_to_code_keyed_labels(): void
    {
        MockClient::global([
            GetVatCodes::class => MockResponse::make([
                'd' => ['results' => [
                    ['ID' => 'v1', 'Code' => '4', 'Description' => 'Hoog', 'Percentage' => 21],
                    ['ID' => 'v2', 'Code' => '2', 'Description' => 'Laag', 'Percentage' => 9],
                ]],
            ], 200),
        ]);

        $codes = (new ExactReferenceData($this->exactConnection()))->vatCodes();

        $this->assertCount(2, $codes);
        $this->assertSame('4 — Hoog (21%)', $codes['4']);
        $this->assertSame('2 — Laag (9%)', $codes['2']);
    }

    public function test_relations_are_mapped_to_guid_keyed_names(): void
    {
        MockClient::global([
            GetRelations::class => MockResponse::make([
                'd' => ['results' => [
                    ['ID' => 'guid-1', 'Name' => 'Klant BV', 'Code' => '   1'],
                ]],
            ], 200),
        ]);

        $relations = (new ExactReferenceData($this->exactConnection()))->relations();

        $this->assertSame(['guid-1' => 'Klant BV'], $relations);
    }

    public function test_mirror_rows_read_through_to_the_last_page(): void
    {
        MockClient::global([
            GetGlAccounts::class => $this->fakePagedBackend([
                ['ID' => 'gl-1', 'Code' => '8000', 'Description' => 'Omzet'],
                ['ID' => 'gl-2', 'Code' => '4000', 'Description' => 'Kosten'],
                ['ID' => 'gl-3', 'Code' => '1300', 'Description' => 'Debiteuren'],
            ], pageSize: 1),
        ]);

        $rows = (new ExactReferenceData($this->exactConnection()))->glAccountRows();

        $this->assertSame(['8000', '4000', '1300'], array_column($rows, 'code'));
    }

    public function test_relations_read_through_to_the_last_page(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakePagedBackend([
                ['ID' => 'guid-1', 'Name' => 'Klant BV', 'Code' => '1'],
                ['ID' => 'guid-2', 'Name' => 'Andere Klant BV', 'Code' => '2'],
            ], pageSize: 1),
        ]);

        $relations = (new ExactReferenceData($this->exactConnection()))->relations();

        $this->assertSame(['guid-1' => 'Klant BV', 'guid-2' => 'Andere Klant BV'], $relations);
    }

    public function test_returns_empty_when_connection_has_no_division(): void
    {
        $connection = $this->exactConnection(['administratie_id' => null]);

        $this->assertSame([], (new ExactReferenceData($connection))->journals());
        $this->assertSame([], (new ExactReferenceData($connection))->relationsByChamberOfCommerce('12345678'));
        $this->assertSame([], (new ExactReferenceData($connection))->relationsByVatNumber('NL803725802B01'));
        $this->assertSame([], (new ExactReferenceData($connection))->relationsByName('Klant BV'));
    }

    public function test_fails_soft_to_empty_on_upstream_error(): void
    {
        MockClient::global([
            GetGlAccounts::class => MockResponse::make(['error' => 'boom'], 500),
        ]);

        $this->assertSame([], (new ExactReferenceData($this->exactConnection()))->glAccounts());
    }

    public function test_chamber_of_commerce_matches_the_raw_value(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'ChamberOfCommerce' => '12345678', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $matches = (new ExactReferenceData($this->exactConnection()))->relationsByChamberOfCommerce('12345678');

        $this->assertCount(1, $matches);
        $this->assertSame('guid-1', $matches[0]['id']);
    }

    public function test_chamber_of_commerce_matches_the_digits_only_variant(): void
    {
        // Exact draagt de KvK soms met spaties — de tweede probe strip alles behalve cijfers.
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'ChamberOfCommerce' => '12345678', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $matches = (new ExactReferenceData($this->exactConnection()))->relationsByChamberOfCommerce('1234 5678');

        $this->assertCount(1, $matches);
        $this->assertSame('guid-1', $matches[0]['id']);
    }

    public function test_chamber_of_commerce_never_falls_back_to_a_full_scan(): void
    {
        // Twee kandidaten die alléén op naam zouden matchen — de KvK-stap mag ze niet
        // vinden zonder een server-side treffer op ChamberOfCommerce.
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Andere Klant BV', 'ChamberOfCommerce' => '', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $matches = (new ExactReferenceData($this->exactConnection()))->relationsByChamberOfCommerce('99999999');

        $this->assertSame([], $matches);
    }

    public function test_chamber_of_commerce_returns_every_candidate_when_ambiguous(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'ChamberOfCommerce' => '12345678', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
                ['ID' => 'guid-2', 'Code' => '2', 'Name' => 'Klant BV Filiaal', 'ChamberOfCommerce' => '12345678', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $matches = (new ExactReferenceData($this->exactConnection()))->relationsByChamberOfCommerce('12345678');

        $this->assertCount(2, $matches);
        $this->assertEqualsCanonicalizing(['guid-1', 'guid-2'], array_column($matches, 'id'));
    }

    public function test_vat_number_matches_exact_without_punctuation_differences(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'VATNumber' => 'NL803725802B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $matches = (new ExactReferenceData($this->exactConnection()))->relationsByVatNumber('NL803725802B01');

        $this->assertCount(1, $matches);
        $this->assertSame('guid-1', $matches[0]['id']);
    }

    public function test_vat_number_matches_dotted_input_via_the_normalized_probe(): void
    {
        // De normalized-probe-variant (input zonder punten) matcht de rauwe waarde
        // rechtstreeks — geen vangnet-scan nodig.
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'VATNumber' => 'NL803725802B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $matches = (new ExactReferenceData($this->exactConnection()))->relationsByVatNumber('NL8037.25.802.B01');

        $this->assertCount(1, $matches);
        $this->assertSame('guid-1', $matches[0]['id']);
    }

    public function test_vat_number_matches_normalized_input_against_dotted_stored_value_via_the_fallback_scan(): void
    {
        // Hier missen beide server-side probes (rauw én genormaliseerd input, geen van
        // beide is de gestippelde opgeslagen waarde): pas het vangnet — volledige scan
        // + lokale normalisatie aan beide kanten — vindt de treffer.
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'VATNumber' => 'NL8037.25.802.B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $matches = (new ExactReferenceData($this->exactConnection()))->relationsByVatNumber('NL803725802B01');

        $this->assertCount(1, $matches);
        $this->assertSame('guid-1', $matches[0]['id']);
    }

    public function test_vat_number_returns_every_candidate_when_ambiguous(): void
    {
        // Twee relaties dragen letterlijk hetzelfde btw-nummer — de server-side probe
        // vindt ze allebei, dus dit hoeft het vangnet niet in.
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'VATNumber' => 'NL803725802B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
                ['ID' => 'guid-2', 'Code' => '2', 'Name' => 'Klant BV Filiaal', 'VATNumber' => 'NL803725802B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $matches = (new ExactReferenceData($this->exactConnection()))->relationsByVatNumber('NL803725802B01');

        $this->assertCount(2, $matches);
        $this->assertEqualsCanonicalizing(['guid-1', 'guid-2'], array_column($matches, 'id'));
    }

    /**
     * De vangnet-scan (getriggerd omdat de probes op de rauwe/genormaliseerde input
     * niets vinden) hertoetst wél lokaal genormaliseerd, en kan zo tóch twee
     * verschillend gestileerde opslagvormen van hetzelfde nummer allebei terugvinden.
     */
    public function test_vat_number_fallback_scan_also_returns_every_candidate_when_ambiguous(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'VATNumber' => 'NL80.37.25.802.B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
                ['ID' => 'guid-2', 'Code' => '2', 'Name' => 'Klant BV Filiaal', 'VATNumber' => 'NL8037.25.802.B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $matches = (new ExactReferenceData($this->exactConnection()))->relationsByVatNumber('NL803725802B01');

        $this->assertCount(2, $matches);
        $this->assertEqualsCanonicalizing(['guid-1', 'guid-2'], array_column($matches, 'id'));
    }

    public function test_vat_number_matches_found_on_second_page(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Andere Klant BV', 'VATNumber' => 'NL111111111B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
                ['ID' => 'guid-2', 'Code' => '2', 'Name' => 'Klant BV', 'VATNumber' => 'NL803725802B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ], pageSize: 1),
        ]);

        $matches = (new ExactReferenceData($this->exactConnection()))->relationsByVatNumber('NL803725802B01');

        $this->assertCount(1, $matches);
        $this->assertSame('guid-2', $matches[0]['id']);
    }

    public function test_vat_number_fallback_returns_no_candidates_when_the_skiptoken_repeats(): void
    {
        // Zonder een geldige server-side treffer valt de VAT-stap terug op de volledige
        // scan (fetchAllPages) — die moet zich ook aan de skiptoken/MAX_PAGES-grenzen houden.
        $mockClient = MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'VATNumber' => 'NL803725802B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ], pageSize: 1, repeatsSkipToken: true),
        ]);

        $matches = (new ExactReferenceData($this->exactConnection()))->relationsByVatNumber('NL999999999B99');

        $this->assertSame([], $matches);
        $this->assertLessThan(10, count($mockClient->getRecordedResponses()));
    }

    public function test_name_matches_after_normalizing_legal_form_and_punctuation(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Acme B.V.', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $matches = (new ExactReferenceData($this->exactConnection()))->relationsByName('Acme BV');

        $this->assertCount(1, $matches);
        $this->assertSame('guid-1', $matches[0]['id']);
    }

    public function test_name_returns_every_candidate_when_two_relations_share_the_normalized_name(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Dubbel BV', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
                ['ID' => 'guid-2', 'Code' => '2', 'Name' => 'Dubbel B.V.', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $matches = (new ExactReferenceData($this->exactConnection()))->relationsByName('Dubbel BV');

        $this->assertCount(2, $matches);
        $this->assertEqualsCanonicalizing(['guid-1', 'guid-2'], array_column($matches, 'id'));
    }

    public function test_name_finds_a_match_split_across_pages(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Andere Klant BV', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
                ['ID' => 'guid-2', 'Code' => '2', 'Name' => 'Klant Zonder Btw BV', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ], pageSize: 1),
        ]);

        $matches = (new ExactReferenceData($this->exactConnection()))->relationsByName('Klant Zonder Btw BV');

        $this->assertCount(1, $matches);
        $this->assertSame('guid-2', $matches[0]['id']);
    }

    public function test_name_returns_no_candidates_when_the_next_page_stream_never_converges(): void
    {
        // Zonder de MAX_PAGES-grens zou dit request voor eeuwig `__next` blijven volgen —
        // ook al staat de treffer al op pagina 1.
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ], pageSize: 1, neverConverges: true),
        ]);

        $matches = (new ExactReferenceData($this->exactConnection()))->relationsByName('Klant BV');

        $this->assertSame([], $matches);
    }

    /**
     * Serveert een vaste set rijen in vensters van `$pageSize`, met een `d.__next`-envelope
     * zolang er nog rijen resten. Geen `$filter`-simulatie — voor de mirror-reads, die
     * filterloos de hele set ophalen.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function fakePagedBackend(array $rows, int $pageSize): \Closure
    {
        return function (PendingRequest $pendingRequest) use ($rows, $pageSize) {
            $offset = (int) ($pendingRequest->query()->get('$skiptoken') ?? 0);
            $envelope = ['results' => array_slice($rows, $offset, $pageSize)];
            $nextOffset = $offset + $pageSize;

            if ($nextOffset < count($rows)) {
                $envelope['__next'] = 'https://start.exactonline.nl/api/v1/123456/next?$skiptoken='.$nextOffset;
            }

            return MockResponse::make(['d' => $envelope], 200);
        };
    }

    /**
     * Simuleert Exact's OData `$filter`-gedrag (letterlijke, hoofdlettergevoelige
     * string-equality — géén normalisatie) tegen een vaste set relaties, zodat de mock
     * hetzelfde onderscheid maakt als de echte API tussen een `eq`-filter op de rauwe
     * input en een filterloze `relationsByName`-full-scan.
     *
     * Met `$pageSize` simuleert de mock ook paginatie: de rauwe lijst wordt in vensters
     * van `$pageSize` opgeknipt en pas per venster gefilterd, met een `d.__next`-envelope
     * zolang er nog rauwe rijen resten. Zo kan een venster nul treffers opleveren terwijl
     * er verderop nog wél een match ligt.
     *
     * `$neverConverges` blijft `__next` sturen voorbij het einde van `$relations` (een
     * server die nooit "klaar" meldt); `$repeatsSkipToken` stuurt telkens hetzelfde token
     * terug (een vastgelopen continuation).
     *
     * @param  list<array<string, mixed>>  $relations
     */
    private function fakeRelationsBackend(
        array $relations,
        ?int $pageSize = null,
        bool $neverConverges = false,
        bool $repeatsSkipToken = false,
    ): \Closure {
        return function (PendingRequest $pendingRequest) use ($relations, $pageSize, $neverConverges, $repeatsSkipToken) {
            $filter = (string) $pendingRequest->query()->get('$filter');
            $offset = (int) ($pendingRequest->query()->get('$skiptoken') ?? 0);

            $window = $pageSize === null
                ? array_slice($relations, $offset)
                : array_slice($relations, $offset, $pageSize);

            $results = $filter === ''
                ? $window
                : array_values(array_filter($window, function (array $relation) use ($filter) {
                    if ($filter === "VATNumber ne ''") {
                        return (string) ($relation['VATNumber'] ?? '') !== '';
                    }

                    if (preg_match("/^VATNumber eq '(.*)'\$/", $filter, $matches) === 1) {
                        return (string) ($relation['VATNumber'] ?? '') === str_replace("''", "'", $matches[1]);
                    }

                    if (preg_match("/^ChamberOfCommerce eq '(.*)'\$/", $filter, $matches) === 1) {
                        return (string) ($relation['ChamberOfCommerce'] ?? '') === str_replace("''", "'", $matches[1]);
                    }

                    return false;
                }));

            $envelope = ['results' => $results];
            $nextOffset = $offset + ($pageSize ?? 0);
            $hasMore = $neverConverges || $repeatsSkipToken || ($pageSize !== null && $nextOffset < count($relations));

            if ($hasMore) {
                $skipToken = $repeatsSkipToken ? 'stuck' : (string) $nextOffset;
                $envelope['__next'] = 'https://start.exactonline.nl/api/v1/123456/crm/Accounts?$skiptoken='.$skipToken;
            }

            return MockResponse::make(['d' => $envelope], 200);
        };
    }
}
