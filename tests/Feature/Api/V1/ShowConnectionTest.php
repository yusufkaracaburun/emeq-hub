<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_consumer_can_read_own_connection_returns_200_with_fingerprint(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_READ]);
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forSnelstart()->for($account)->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/v1/connections/{$connection->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $connection->id)
            ->assertJsonPath('data.provider', 'snelstart');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', (string) $response->json('data.fingerprint'));
        $this->assertArrayNotHasKey('client_key', (array) $response->json('data'));
    }

    public function test_other_consumers_connection_returns_404_with_connection_not_found(): void
    {
        $consumerA = Consumer::factory()->create();
        $accountA = Account::factory()->for($consumerA)->create();
        $connectionA = Connection::factory()->forSnelstart()->for($accountA)->create();

        [, $tokenB] = $this->consumerWithToken([TokenAbilities::SNELSTART_READ]);

        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->getJson("/v1/connections/{$connectionA->id}")
            ->assertNotFound()
            ->assertJsonPath('error', 'connection_not_found');
    }

    public function test_token_without_required_ability_returns_403(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::MOLLIE_READ]);
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forSnelstart()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/v1/connections/{$connection->id}")
            ->assertForbidden();
    }

    public function test_exact_read_token_can_read_own_exact_connection(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::EXACT_READ]);
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forExact()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/v1/connections/{$connection->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $connection->id)
            ->assertJsonPath('data.provider', 'exact');
    }

    public function test_integrations_manage_token_can_read_any_connection(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::INTEGRATIONS_MANAGE]);
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()->forMollie()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/v1/connections/{$connection->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $connection->id);
    }

    public function test_revoked_connection_is_still_returnable_via_show(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_READ]);
        $account = Account::factory()->for($consumer)->create();
        $connection = Connection::factory()
            ->forSnelstart()
            ->for($account)
            ->create(['revoked_at' => now()]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/v1/connections/{$connection->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $connection->id);

        $this->assertNotNull($response->json('data.revoked_at'));
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
