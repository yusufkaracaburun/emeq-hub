<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\DataForSeo\Http\Api;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BacklinksSummaryResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/v1/dataforseo/backlinks-summary?target=example.com')
            ->assertStatus(401);
    }

    public function test_missing_x_account_id_header_returns_400_with_missing_account_header(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::DATAFORSEO_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/dataforseo/backlinks-summary?target=example.com')
            ->assertStatus(400)
            ->assertJsonPath('error', 'missing_account_header');
    }

    public function test_unknown_x_account_id_returns_404_with_account_not_found(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::DATAFORSEO_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-unknown')
            ->getJson('/v1/dataforseo/backlinks-summary?target=example.com')
            ->assertStatus(404)
            ->assertJsonPath('error', 'account_not_found');
    }

    public function test_account_without_active_dataforseo_connection_returns_404_with_connection_not_found(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::DATAFORSEO_READ]);
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forDataForSeo()->for($account)->create(['revoked_at' => now()]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/dataforseo/backlinks-summary?target=example.com')
            ->assertStatus(404)
            ->assertJsonPath('error', 'connection_not_found');
    }

    public function test_other_consumers_account_id_returns_404_not_403(): void
    {
        $consumerA = Consumer::factory()->create();
        $accountA = Account::factory()->for($consumerA)->create(['external_id' => 'school-A']);
        Connection::factory()->forDataForSeo()->for($accountA)->create();

        [, $tokenB] = $this->consumerWithToken([TokenAbilities::DATAFORSEO_READ]);

        $this->withHeader('Authorization', "Bearer {$tokenB}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/dataforseo/backlinks-summary?target=example.com')
            ->assertStatus(404)
            ->assertJsonPath('error', 'account_not_found');
    }

    public function test_missing_target_parameter_returns_422(): void
    {
        [$consumer, $token] = $this->consumerWithToken([TokenAbilities::DATAFORSEO_READ]);
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forDataForSeo()->for($account)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/dataforseo/backlinks-summary')
            ->assertStatus(422)
            ->assertJsonPath('error', 'missing_target');
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
