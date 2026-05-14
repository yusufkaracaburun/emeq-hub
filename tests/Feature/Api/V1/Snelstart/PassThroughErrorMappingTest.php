<?php

namespace Tests\Feature\Api\V1\Snelstart;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Models\PassThroughCall;
use App\Sanctum\TokenAbilities;
use Emeq\SnelstartApi\Http\Request\RawSnelstartRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Tests\Concerns\PrimesSnelstartTokenCache;
use Tests\TestCase;

class PassThroughErrorMappingTest extends TestCase
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

    public function test_snelstart_401_maps_to_502_with_snelstart_auth_short_code(): void
    {
        [, $token, $account] = $this->setupSnelstartConsumer();

        MockClient::global([
            RawSnelstartRequest::class => MockResponse::make(['error' => 'unauthorized'], 401),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', $account->external_id)
            ->getJson('/v1/snelstart/echo/ping');

        $response->assertStatus(502);
        $response->assertJsonPath('error', 'upstream_error');

        $row = PassThroughCall::query()->first();
        $this->assertSame('snelstart_auth', $row->upstream_error);
        $this->assertSame(502, $row->status);
    }

    public function test_snelstart_503_maps_to_502_with_snelstart_5xx_short_code(): void
    {
        [, $token, $account] = $this->setupSnelstartConsumer();

        MockClient::global([
            RawSnelstartRequest::class => MockResponse::make('gateway error', 503),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', $account->external_id)
            ->getJson('/v1/snelstart/echo/ping');

        $response->assertStatus(502);
        $response->assertJsonPath('error', 'upstream_error');

        $row = PassThroughCall::query()->first();
        $this->assertSame('snelstart_5xx', $row->upstream_error);
    }

    public function test_snelstart_400_passes_through_as_400_with_upstream_validation(): void
    {
        [, $token, $account] = $this->setupSnelstartConsumer();

        MockClient::global([
            RawSnelstartRequest::class => MockResponse::make(
                ['error' => 'Het ID dient leeg te zijn. Foutcode: ALG-0100'],
                400,
            ),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', $account->external_id)
            ->getJson('/v1/snelstart/relaties');

        $response->assertStatus(400);
        $response->assertJsonPath('error', 'upstream_validation');
        $response->assertJsonPath('error_codes.0', 'ALG-0100');

        $row = PassThroughCall::query()->first();
        $this->assertNull($row->upstream_error, '4xx user-input errors mogen geen short-code krijgen');
        $this->assertSame(400, $row->status);
    }

    public function test_snelstart_404_passes_through_as_404(): void
    {
        [, $token, $account] = $this->setupSnelstartConsumer();

        MockClient::global([
            RawSnelstartRequest::class => MockResponse::make(['error' => 'not found'], 404),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', $account->external_id)
            ->getJson('/v1/snelstart/relaties/deleted-guid');

        $response->assertStatus(404);
        $response->assertJsonPath('error', 'upstream_not_found');

        $row = PassThroughCall::query()->first();
        $this->assertNull($row->upstream_error);
    }

    public function test_snelstart_429_passes_through_with_retry_after_header(): void
    {
        [, $token, $account] = $this->setupSnelstartConsumer();

        MockClient::global([
            RawSnelstartRequest::class => MockResponse::make(
                body: 'throttled',
                status: 429,
                headers: ['Retry-After' => '30'],
            ),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', $account->external_id)
            ->getJson('/v1/snelstart/relaties');

        $response->assertStatus(429);
        $this->assertSame('30', $response->headers->get('Retry-After'));

        $row = PassThroughCall::query()->first();
        $this->assertNull($row->upstream_error);
    }

    public function test_network_timeout_maps_to_504_with_snelstart_timeout_short_code(): void
    {
        [, $token, $account] = $this->setupSnelstartConsumer();

        MockClient::global([
            RawSnelstartRequest::class => function (PendingRequest $pr): never {
                throw new FatalRequestException(
                    new RuntimeException('Connection timed out'),
                    $pr,
                );
            },
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', $account->external_id)
            ->getJson('/v1/snelstart/echo/ping');

        $response->assertStatus(504);
        $response->assertJsonPath('error', 'upstream_timeout');

        $row = PassThroughCall::query()->first();
        $this->assertSame('snelstart_timeout', $row->upstream_error);
        $this->assertSame(504, $row->status);
    }

    /**
     * @return array{0: Consumer, 1: string, 2: Account}
     */
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
