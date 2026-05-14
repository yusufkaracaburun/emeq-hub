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
        // /v1/ping zelf eist geen specifieke ability — auth:sanctum is genoeg.
        // Deze test bewijst dat een token met `snelstart:read` (uit TokenAbilities)
        // wordt geaccepteerd door de sanctum-guard, zonder dat we vandaag al een
        // ability-middleware-check hebben.
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
        // /v1/snelstart/{path} eist `snelstart:read` (of write/*) op GET — een
        // token met alleen `mollie:read` moet 403 insufficient_ability krijgen.
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
}
