<?php

namespace Tests\Feature\Api\V1\Snelstart;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\PassThroughCall;
use App\Sanctum\TokenAbilities;
use Emeq\SnelstartApi\Http\Request\RawSnelstartRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Tests\Concerns\PrimesSnelstartTokenCache;
use Tests\TestCase;

class PassThroughOdataRelatiesTest extends TestCase
{
    use PrimesSnelstartTokenCache;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MockClient::destroyGlobal();
        config(['snelstart.http.retry.times' => 1, 'snelstart.http.retry.sleep' => 0]);
    }

    protected function tearDown(): void
    {
        MockClient::destroyGlobal();
        parent::tearDown();
    }

    public function test_get_relaties_with_top_5_query_string_is_proxied_verbatim_to_sdk(): void
    {
        [, $token, $account] = $this->setupSnelstartConsumer();

        $captured = [];
        MockClient::global([
            RawSnelstartRequest::class => function (PendingRequest $pr) use (&$captured) {
                $captured = [
                    'query' => $pr->query()->all(),
                    'url' => $pr->getUrl(),
                    'method' => $pr->getMethod()->value,
                ];

                return MockResponse::make(['value' => [['id' => 'r-1'], ['id' => 'r-2']]], 200);
            },
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', $account->external_id)
            ->getJson('/v1/snelstart/relaties?%24top=5');

        $response->assertOk();
        $response->assertJsonPath('value.0.id', 'r-1');

        $this->assertSame('5', (string) ($captured['query']['$top'] ?? null), 'Snelstart SDK kreeg $top=5 niet door');
        $this->assertSame('GET', $captured['method']);
        $this->assertStringContainsString('/relaties', $captured['url']);

        $row = PassThroughCall::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('/relaties', $row->path);
        $this->assertNotNull($row->query_keys);
        $this->assertStringContainsString('$top', (string) $row->query_keys);
    }

    public function test_complex_odata_query_stores_only_query_keys_no_values_in_audit(): void
    {
        [, $token, $account] = $this->setupSnelstartConsumer();

        MockClient::global([
            RawSnelstartRequest::class => MockResponse::make(['value' => []], 200),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', $account->external_id)
            ->getJson('/v1/snelstart/relaties?%24filter=Email%20eq%20%27a%40b.nl%27&%24select=Id%2CNaam&%24top=10')
            ->assertOk();

        $row = (array) DB::table('pass_through_calls')->latest('id')->first();

        foreach ($row as $col => $val) {
            if (is_string($val)) {
                $this->assertStringNotContainsString('a@b.nl', $val, "Audit-kolom {$col} lekt e-mail uit OData filter.");
                $this->assertStringNotContainsString('Email eq', $val, "Audit-kolom {$col} lekt filter-expression.");
            }
        }

        $this->assertSame('/relaties', $row['path']);
        $this->assertNotNull($row['query_keys']);
        $keys = explode(',', (string) $row['query_keys']);
        $this->assertContains('$filter', $keys);
        $this->assertContains('$select', $keys);
        $this->assertContains('$top', $keys);
    }

    public function test_complex_odata_query_with_filter_and_select_is_proxied(): void
    {
        [, $token, $account] = $this->setupSnelstartConsumer();

        $captured = [];
        MockClient::global([
            RawSnelstartRequest::class => function (PendingRequest $pr) use (&$captured) {
                $captured = $pr->query()->all();

                return MockResponse::make(['value' => []], 200);
            },
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', $account->external_id)
            ->getJson('/v1/snelstart/relaties?%24filter=Email%20eq%20%27a%40b.nl%27&%24select=Id%2CNaam&%24top=10')
            ->assertOk();

        $this->assertSame("Email eq 'a@b.nl'", $captured['$filter'] ?? null);
        $this->assertSame('Id,Naam', $captured['$select'] ?? null);
        $this->assertSame('10', (string) ($captured['$top'] ?? null));
    }

    public function test_response_content_type_is_passthrough(): void
    {
        [, $token, $account] = $this->setupSnelstartConsumer();

        MockClient::global([
            RawSnelstartRequest::class => MockResponse::make(
                body: '<?xml version="1.0"?><entry/>',
                status: 200,
                headers: ['Content-Type' => 'application/atom+xml'],
            ),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', $account->external_id)
            ->get('/v1/snelstart/relaties');

        $response->assertOk();
        $this->assertSame('application/atom+xml', $response->headers->get('Content-Type'));
    }

    /** @return array{0: Consumer, 1: string, 2: Account} */
    private function setupSnelstartConsumer(): array
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        $connection = Connection::factory()->forSnelstart()->for($account)->create();
        $this->primeSnelstartToken($connection);
        $token = $consumer->createToken('test', [TokenAbilities::SNELSTART_READ])->plainTextToken;

        return [$consumer, $token, $account];
    }
}
