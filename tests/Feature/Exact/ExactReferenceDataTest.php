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

    public function test_returns_empty_when_connection_has_no_division(): void
    {
        $connection = $this->exactConnection(['administratie_id' => null]);

        $this->assertSame([], (new ExactReferenceData($connection))->journals());
    }

    public function test_fails_soft_to_empty_on_upstream_error(): void
    {
        MockClient::global([
            GetGlAccounts::class => MockResponse::make(['error' => 'boom'], 500),
        ]);

        $this->assertSame([], (new ExactReferenceData($this->exactConnection()))->glAccounts());
    }

    public function test_find_relation_matches_exact_vat_number_without_punctuation_differences(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'VATNumber' => 'NL803725802B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $match = (new ExactReferenceData($this->exactConnection()))->findRelation('NL803725802B01', 'Klant BV');

        $this->assertNotNull($match);
        $this->assertSame('guid-1', $match['id']);
    }

    public function test_find_relation_matches_dotted_input_against_normalized_stored_vat_number(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'VATNumber' => 'NL803725802B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $match = (new ExactReferenceData($this->exactConnection()))->findRelation('NL8037.25.802.B01', 'Klant BV');

        $this->assertNotNull($match);
        $this->assertSame('guid-1', $match['id']);
    }

    public function test_find_relation_matches_normalized_input_against_dotted_stored_vat_number(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'VATNumber' => 'NL8037.25.802.B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $match = (new ExactReferenceData($this->exactConnection()))->findRelation('NL803725802B01', 'Klant BV');

        $this->assertNotNull($match);
        $this->assertSame('guid-1', $match['id']);
    }

    public function test_find_relation_falls_back_to_name_when_no_relation_has_the_vat_number(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-2', 'Code' => '2', 'Name' => 'Klant Zonder Btw BV', 'VATNumber' => '', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $match = (new ExactReferenceData($this->exactConnection()))->findRelation('NL803725802B01', 'Klant Zonder Btw BV');

        $this->assertNotNull($match);
        $this->assertSame('guid-2', $match['id']);
    }

    public function test_find_relation_returns_null_when_two_relations_share_the_same_name(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Dubbel BV', 'VATNumber' => '', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
                ['ID' => 'guid-2', 'Code' => '2', 'Name' => 'Dubbel BV', 'VATNumber' => '', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $match = (new ExactReferenceData($this->exactConnection()))->findRelation(null, 'Dubbel BV');

        $this->assertNull($match);
    }

    public function test_find_relation_returns_null_on_ambiguous_vat_number_without_falling_back_to_name(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'VATNumber' => 'NL803725802B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
                ['ID' => 'guid-2', 'Code' => '2', 'Name' => 'Klant BV Filiaal', 'VATNumber' => 'NL8037.25.802.B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
                // Zou op naam wél uniek matchen — mag niet gebruikt worden ná een ambigu btw-nummer.
                ['ID' => 'guid-3', 'Code' => '3', 'Name' => 'Klant BV', 'VATNumber' => '', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ]),
        ]);

        $match = (new ExactReferenceData($this->exactConnection()))->findRelation('NL803725802B01', 'Klant BV');

        $this->assertNull($match);
    }

    public function test_find_relation_matches_vat_number_found_on_second_page(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Andere Klant BV', 'VATNumber' => 'NL111111111B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
                ['ID' => 'guid-2', 'Code' => '2', 'Name' => 'Klant BV', 'VATNumber' => 'NL803725802B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ], pageSize: 1),
        ]);

        $match = (new ExactReferenceData($this->exactConnection()))->findRelation('NL803725802B01', 'Klant BV');

        $this->assertNotNull($match);
        $this->assertSame('guid-2', $match['id']);
    }

    public function test_find_relation_returns_null_when_two_relations_share_the_same_vat_number_split_across_pages(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'VATNumber' => 'NL803725802B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
                ['ID' => 'guid-2', 'Code' => '2', 'Name' => 'Klant BV Filiaal', 'VATNumber' => 'NL8037.25.802.B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ], pageSize: 1),
        ]);

        $match = (new ExactReferenceData($this->exactConnection()))->findRelation('NL803725802B01', 'Klant BV');

        $this->assertNull($match);
    }

    public function test_find_relation_falls_back_to_name_match_found_on_second_page(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Andere Klant BV', 'VATNumber' => '', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
                ['ID' => 'guid-2', 'Code' => '2', 'Name' => 'Klant Zonder Btw BV', 'VATNumber' => '', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ], pageSize: 1),
        ]);

        $match = (new ExactReferenceData($this->exactConnection()))->findRelation(null, 'Klant Zonder Btw BV');

        $this->assertNotNull($match);
        $this->assertSame('guid-2', $match['id']);
    }

    public function test_find_relation_returns_null_when_two_relations_share_the_same_name_split_across_pages(): void
    {
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Dubbel BV', 'VATNumber' => '', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
                ['ID' => 'guid-2', 'Code' => '2', 'Name' => 'Dubbel BV', 'VATNumber' => '', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ], pageSize: 1),
        ]);

        $match = (new ExactReferenceData($this->exactConnection()))->findRelation(null, 'Dubbel BV');

        $this->assertNull($match);
    }

    public function test_find_relation_returns_null_when_the_next_page_stream_never_converges(): void
    {
        // Zonder de MAX_PAGES-grens zou dit request voor eeuwig `__next` blijven volgen —
        // ook al staat de treffer al op pagina 1.
        MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'VATNumber' => 'NL803725802B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ], pageSize: 1, neverConverges: true),
        ]);

        $match = (new ExactReferenceData($this->exactConnection()))->findRelation('NL803725802B01', 'Klant BV');

        $this->assertNull($match);
    }

    public function test_find_relation_returns_null_when_the_skiptoken_repeats(): void
    {
        $mockClient = MockClient::global([
            GetRelations::class => $this->fakeRelationsBackend([
                ['ID' => 'guid-1', 'Code' => '1', 'Name' => 'Klant BV', 'VATNumber' => 'NL803725802B01', 'IsSales' => true, 'IsSupplier' => false, 'Status' => 'C'],
            ], pageSize: 1, repeatsSkipToken: true),
        ]);

        $match = (new ExactReferenceData($this->exactConnection()))->findRelation('NL803725802B01', 'Klant BV');

        $this->assertNull($match);
        // Herhaling wordt meteen herkend — geen 500 requests nodig om de grens te raken.
        $this->assertLessThan(10, count($mockClient->getRecordedResponses()));
    }

    /**
     * Simuleert Exact's OData `$filter`-gedrag (letterlijke, hoofdlettergevoelige
     * string-equality — géén normalisatie) tegen een vaste set relaties, zodat de
     * mock hetzelfde onderscheid maakt als de echte API tussen een `eq`-filter op
     * de rauwe input en een brede `ne ''`-candidate-fetch.
     *
     * Met `$pageSize` simuleert de mock ook paginatie: de rauwe lijst wordt in
     * vensters van `$pageSize` opgeknipt en pas per venster gefilterd, met een
     * `d.__next`-envelope zolang er nog rauwe rijen resten. Zo kan een venster nul
     * treffers opleveren terwijl er verderop nog wél een match ligt — precies het
     * geval waarin doorpagineren het verschil maakt tussen een gemiste of een
     * stilletjes verkeerd gekozen relatie.
     *
     * `$neverConverges` blijft `__next` sturen voorbij het einde van `$relations` (een
     * server die nooit "klaar" meldt); `$repeatsSkipToken` stuurt telkens hetzelfde
     * token terug (een vastgelopen continuation). Beide simuleren een andere
     * paginatie-storing dan de gewone `$pageSize`-vensters hierboven.
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

            $results = array_values(array_filter($window, function (array $relation) use ($filter) {
                if ($filter === "VATNumber ne ''") {
                    return (string) ($relation['VATNumber'] ?? '') !== '';
                }

                if (preg_match("/^VATNumber eq '(.*)'\$/", $filter, $matches) === 1) {
                    return (string) ($relation['VATNumber'] ?? '') === str_replace("''", "'", $matches[1]);
                }

                if (preg_match("/^Name eq '(.*)'\$/", $filter, $matches) === 1) {
                    return (string) ($relation['Name'] ?? '') === str_replace("''", "'", $matches[1]);
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
