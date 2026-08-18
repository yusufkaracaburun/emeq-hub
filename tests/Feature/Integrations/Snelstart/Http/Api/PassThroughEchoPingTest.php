<?php

namespace Tests\Feature\Integrations\Snelstart\Http\Api;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\PassThroughCall;
use App\Sanctum\TokenAbilities;
use Emeq\SnelstartApi\Http\Request\RawSnelstartRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Feature\Integrations\Snelstart\Concerns\PrimesSnelstartTokenCache;
use Tests\TestCase;

class PassThroughEchoPingTest extends TestCase
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

    public function test_get_echo_ping_proxies_through_sdk_with_credential_resolver_binding_and_returns_200(): void
    {
        [$consumer, $token, $account, $connection] = $this->setupSnelstartConsumer([TokenAbilities::SNELSTART_READ]);
        $this->primeSnelstartToken($connection);

        MockClient::global([
            RawSnelstartRequest::class => MockResponse::make(['pong' => 'ok', 'echoed' => 'ping'], 200),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', $account->external_id)
            ->getJson('/v1/snelstart/echo/ping');

        $response->assertOk();
        $response->assertJsonPath('pong', 'ok');
        $response->assertJsonPath('echoed', 'ping');

        $this->assertSame(1, PassThroughCall::count());

        $row = PassThroughCall::query()->first();
        $this->assertSame('GET', $row->method);
        $this->assertStringStartsWith('/echo/ping', $row->path);
        $this->assertSame(200, $row->status);
        $this->assertNull($row->request_fingerprint, 'GET-requests hebben geen body en dus geen fingerprint');
        $this->assertSame($consumer->getKey(), $row->consumer_id);
        $this->assertSame($account->getKey(), $row->account_id);
        $this->assertSame($connection->getKey(), $row->connection_id);
        $this->assertNull($row->upstream_error);
    }

    public function test_credential_resolver_was_bound_to_the_right_connections_credentials_during_call(): void
    {
        $consumer = Consumer::factory()->create();
        $accountA = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        $accountB = Account::factory()->for($consumer)->create(['external_id' => 'school-B']);
        $connA = Connection::factory()->forSnelstart()->for($accountA)->create();
        $connB = Connection::factory()->forSnelstart()->for($accountB)->create();
        $this->primeSnelstartToken($connA);
        $this->primeSnelstartToken($connB);
        $token = $consumer->createToken('test', [TokenAbilities::SNELSTART_READ])->plainTextToken;

        MockClient::global([
            RawSnelstartRequest::class => MockResponse::make(['ok' => true], 200),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/snelstart/echo/ping')
            ->assertOk();

        $row = PassThroughCall::query()->first();
        $this->assertNotNull($row);
        $this->assertSame($connA->getKey(), $row->connection_id, 'Audit-rij moet Account A\'s Connection bevatten');
        $this->assertSame($accountA->getKey(), $row->account_id);
    }

    public function test_token_with_only_mollie_read_ability_returns_403_on_snelstart_get(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forSnelstart()->for($account)->create();
        $token = $consumer->createToken('mollie-only', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        MockClient::global([
            RawSnelstartRequest::class => MockResponse::make(['should' => 'not-be-called'], 200),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/snelstart/echo/ping')
            ->assertStatus(403)
            ->assertJsonPath('error', 'insufficient_ability');

        $this->assertSame(0, PassThroughCall::count(), 'Ability-fail mag geen audit-rij produceren');
    }

    public function test_post_with_non_json_content_type_returns_415_and_writes_no_audit_row(): void
    {
        [, $token, $account, $connection] = $this->setupSnelstartConsumer([TokenAbilities::SNELSTART_WRITE]);
        $this->primeSnelstartToken($connection);

        MockClient::global([
            RawSnelstartRequest::class => MockResponse::make(['should' => 'not-be-called'], 500),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', $account->external_id)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->post('/v1/snelstart/relaties', ['naam' => 'should-not-pass']);

        $response->assertStatus(415);
        $response->assertJsonPath('error', 'unsupported_content_type');
        $this->assertSame(0, PassThroughCall::count(), '415-pad mag geen audit-rij schrijven');
    }

    /**
     * @param  list<string>  $abilities
     * @return array{0: Consumer, 1: string, 2: Account, 3: Connection}
     */
    private function setupSnelstartConsumer(array $abilities): array
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        $connection = Connection::factory()->forSnelstart()->for($account)->create();
        $token = $consumer->createToken('test', $abilities)->plainTextToken;

        return [$consumer, $token, $account, $connection];
    }
}
