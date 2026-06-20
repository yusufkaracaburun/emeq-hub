<?php

namespace Tests\Feature\Api\OAuth;

use App\Models\Consumer;
use App\OAuth\Exact\ExactOAuthFlow;
use App\OAuth\Testing\FakeOAuthFlow;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * De provider-agnostische init-controller (ProviderInitController) achter de
 * named mollie/exact-routes én de generieke /oauth/{provider}/init-route.
 */
class GenericInitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(ExactOAuthFlow::class, FakeOAuthFlow::class);
    }

    public function test_integrations_manage_ability_authorizes_init(): void
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);
        // Eén provider-agnostische PAT i.p.v. exact:write.
        $token = $consumer->createToken('t', [TokenAbilities::INTEGRATIONS_MANAGE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/exact/init', ['account_external_id' => 'school1'])
            ->assertOk()
            ->assertJsonStructure(['connection_id', 'redirect_url']);

        $this->assertDatabaseHas('connections', [
            'account_id' => $account->id,
            'provider' => 'exact',
            'status' => 'pending',
        ]);
    }

    public function test_unknown_provider_returns_404(): void
    {
        $consumer = Consumer::factory()->create();
        $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'School 1']);
        $token = $consumer->createToken('t', [TokenAbilities::INTEGRATIONS_MANAGE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/moneybird/init', ['account_external_id' => 'school1'])
            ->assertNotFound();
    }

    public function test_non_connectable_provider_returns_404(): void
    {
        // Snelstart heeft geen OAuth-flow → niet via deze route koppelbaar.
        $consumer = Consumer::factory()->create();
        $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'School 1']);
        $token = $consumer->createToken('t', [TokenAbilities::INTEGRATIONS_MANAGE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/snelstart/init', ['account_external_id' => 'school1'])
            ->assertNotFound();
    }

    public function test_init_without_ability_returns_403(): void
    {
        $consumer = Consumer::factory()->create();
        $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'School 1']);
        $token = $consumer->createToken('t', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/exact/init', ['account_external_id' => 'school1'])
            ->assertForbidden();
    }
}
