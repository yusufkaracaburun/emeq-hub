<?php

namespace Tests\Feature\Api\OAuth;

use App\Models\Consumer;
use App\OAuth\Mollie\MollieConnectOAuthFlow;
use App\OAuth\Testing\FakeOAuthFlow;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind FakeOAuthFlow zodat de Registry de fake serveert i.p.v. echte Mollie-call.
        $this->app->bind(MollieConnectOAuthFlow::class, FakeOAuthFlow::class);
    }

    public function test_init_creates_pending_connection_and_returns_redirect_url(): void
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);
        $token = $consumer->createToken('t', [TokenAbilities::MOLLIE_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/mollie/init', ['account_external_id' => 'school1'])
            ->assertOk()
            ->assertJsonStructure(['connection_id', 'redirect_url']);

        $this->assertDatabaseHas('connections', [
            'account_id' => $account->id,
            'provider' => 'mollie',
            'status' => 'pending',
        ]);
    }

    public function test_init_without_ability_returns_403(): void
    {
        $consumer = Consumer::factory()->create();
        $consumer->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);
        $token = $consumer->createToken('t', [TokenAbilities::MOLLIE_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/mollie/init', ['account_external_id' => 'school1'])
            ->assertForbidden();
    }

    public function test_init_with_cross_consumer_account_returns_404(): void
    {
        $consumerA = Consumer::factory()->create();
        $consumerB = Consumer::factory()->create();
        $consumerB->accounts()->create([
            'external_id' => 'b-only',
            'display_name' => 'B-only',
        ]);

        $tokenA = $consumerA->createToken('t', [TokenAbilities::MOLLIE_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/v1/oauth/mollie/init', ['account_external_id' => 'b-only'])
            ->assertNotFound();
    }
}
