<?php

namespace Tests\Feature\Api;

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
        // /v1/ping eist (in Phase 3) geen specifieke ability — alleen auth:sanctum.
        // Wanneer Phase 5b een route met ->middleware('ability:snelstart:read')
        // toevoegt, wordt deze test ingevuld met een 403-assertion op een
        // token met andere abilities.
        $this->markTestIncomplete('Wacht op /v1/snelstart/* met ability:snelstart:read in Phase 5b');
    }
}
