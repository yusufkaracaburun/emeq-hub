<?php

namespace Tests\Feature\Exact;

use App\Models\Connection;
use App\Models\Consumer;
use App\Services\Exact\ExactReferenceData;
use Emeq\ExactApi\Http\Request\Read\GetGlAccounts;
use Emeq\ExactApi\Http\Request\Read\GetRelations;
use Emeq\ExactApi\Http\Request\Read\GetVatCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
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
}
