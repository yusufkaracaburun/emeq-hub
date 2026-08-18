<?php

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SanctumAbilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_wildcard_grants_access_to_any_route(): void
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer->createToken('admin', [TokenAbilities::ADMIN])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/ping')
            ->assertOk();
    }

    public function test_token_with_specific_ability_can_reach_ping(): void
    {
        $consumer = Consumer::factory()->create();
        $token = $consumer
            ->createToken('snel-read', [TokenAbilities::SNELSTART_READ])
            ->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/ping')
            ->assertOk()
            ->assertJsonPath('abilities.0', TokenAbilities::SNELSTART_READ);
    }

    public function test_token_without_required_ability_is_rejected(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forSnelstart()->for($account)->create();

        $token = $consumer
            ->createToken('mollie-only', [TokenAbilities::MOLLIE_READ])
            ->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/snelstart/echo/ping')
            ->assertStatus(403)
            ->assertJsonPath('error', 'insufficient_ability');
    }

    public function test_token_with_only_snelstart_read_ability_is_rejected_on_mollie_get(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forMollie()->active()->for($account)->create();

        $token = $consumer
            ->createToken('snelstart-only', [TokenAbilities::SNELSTART_READ])
            ->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/mollie/payment-methods')
            ->assertStatus(403)
            ->assertJsonPath('error', 'insufficient_ability');
    }

    public function test_token_with_only_mollie_read_ability_is_rejected_on_mollie_post(): void
    {
        $consumer = Consumer::factory()->create();
        $account = Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        Connection::factory()->forMollie()->active()->for($account)->create();

        $token = $consumer
            ->createToken('mollie-read-only', [TokenAbilities::MOLLIE_READ])
            ->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->postJson('/v1/mollie/payments', [
                'description' => 'test',
                'amount' => ['currency' => 'EUR', 'value' => '10.00'],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error', 'insufficient_ability');
    }
}
