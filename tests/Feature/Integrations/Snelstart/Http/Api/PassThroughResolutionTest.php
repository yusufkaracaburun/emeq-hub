<?php

namespace Tests\Feature\Integrations\Snelstart\Http\Api;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Tests\TestCase;

class PassThroughResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MockClient::destroyGlobal();
    }

    public function test_missing_x_account_id_header_returns_400_with_missing_account_header(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/snelstart/echo/ping')
            ->assertStatus(400)
            ->assertJsonPath('error', 'missing_account_header');
    }

    public function test_unknown_x_account_id_returns_404_with_account_not_found(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-unknown')
            ->getJson('/v1/snelstart/echo/ping')
            ->assertStatus(404)
            ->assertJsonPath('error', 'account_not_found');
    }

    public function test_other_consumers_account_id_returns_404_not_403(): void
    {
        $consumerA = Consumer::factory()->create();
        $accountA = Account::factory()->for($consumerA)->create(['external_id' => 'school-A']);
        Connection::factory()->forSnelstart()->for($accountA)->create();

        [, $tokenB] = $this->consumerWithToken([TokenAbilities::SNELSTART_READ]);

        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/snelstart/echo/ping')
            ->assertStatus(404)
            ->assertJsonPath('error', 'account_not_found');
    }

    public function test_account_without_active_snelstart_connection_returns_404_with_connection_not_found(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_READ]);
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forSnelstart()->for($account)->create(['revoked_at' => now()]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/snelstart/echo/ping')
            ->assertStatus(404)
            ->assertJsonPath('error', 'connection_not_found');
    }

    public function test_account_with_only_mollie_connection_returns_404_with_connection_not_found(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_READ]);
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forMollie()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/snelstart/echo/ping')
            ->assertStatus(404)
            ->assertJsonPath('error', 'connection_not_found');
    }

    public function test_options_method_returns_405_with_method_not_allowed(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_READ]);
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forSnelstart()->for($account)->create();

        $this->call(
            'OPTIONS',
            '/v1/snelstart/echo/ping',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => "Bearer {$token}",
                'HTTP_X_ACCOUNT_ID' => 'school-A',
            ]
        )
            ->assertStatus(405)
            ->assertHeader('Allow', 'GET, POST, PATCH, DELETE');
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/v1/snelstart/echo/ping')
            ->assertStatus(401);
    }

    /**
     * @param  list<string>  $abilities
     * @return array{0: Consumer, 1: string}
     */
    private function consumerWithToken(array $abilities): array
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('test', $abilities)->plainTextToken;

        return [$consumer, $token];
    }
}
