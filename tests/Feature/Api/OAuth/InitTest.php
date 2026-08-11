<?php

namespace Tests\Feature\Api\OAuth;

use App\Integrations\Mollie\OAuth\MollieConnectOAuthFlow;
use App\Integrations\OAuth\Testing\FakeOAuthFlow;
use App\Models\Connection;
use App\Models\Consumer;
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

    public function test_repeated_init_reuses_pending_connection_instead_of_stacking(): void
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);
        $token = $consumer->createToken('t', [TokenAbilities::MOLLIE_WRITE])->plainTextToken;

        $first = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/mollie/init', ['account_external_id' => 'school1'])
            ->assertOk()->json('connection_id');

        $second = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/mollie/init', ['account_external_id' => 'school1'])
            ->assertOk()->json('connection_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Connection::where('account_id', $account->id)->where('provider', 'mollie')->count());
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

    public function test_init_creates_consumer_scoped_account_without_touching_other_consumer(): void
    {
        $consumerA = Consumer::factory()->create();
        $consumerB = Consumer::factory()->create();
        $accountB = $consumerB->accounts()->create([
            'external_id' => 'b-only',
            'display_name' => 'B-only',
        ]);

        $tokenA = $consumerA->createToken('t', [TokenAbilities::MOLLIE_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/v1/oauth/mollie/init', ['account_external_id' => 'b-only'])
            ->assertOk();

        // external_id is per-Consumer genamespaced: A krijgt een eigen 'b-only'-account,
        // B's gelijknamige account blijft ongemoeid (geen cross-consumer reuse).
        $accountA = $consumerA->accounts()->where('external_id', 'b-only')->sole();
        $this->assertNotSame($accountB->id, $accountA->id);
        $this->assertSame(0, $accountB->connections()->count());
    }
}
