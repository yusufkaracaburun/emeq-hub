<?php

namespace Tests\Feature\Api\OAuth;

use App\Models\Connection;
use App\Models\Consumer;
use App\OAuth\Exact\ExactOAuthFlow;
use App\OAuth\Testing\FakeOAuthFlow;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExactInitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(ExactOAuthFlow::class, FakeOAuthFlow::class);
    }

    public function test_init_creates_pending_connection_and_returns_redirect_url(): void
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

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

    public function test_repeated_init_reuses_pending_connection_instead_of_stacking(): void
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $first = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/exact/init', ['account_external_id' => 'school1'])
            ->assertOk()->json('connection_id');

        $second = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/exact/init', ['account_external_id' => 'school1'])
            ->assertOk()->json('connection_id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Connection::where('account_id', $account->id)->where('provider', 'exact')->count());
    }

    public function test_relink_of_active_connection_reuses_row_and_preserves_tokens(): void
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);
        $active = Connection::factory()->forExact()->active()->create([
            'account_id' => $account->id,
        ]);
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $reused = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/exact/init', ['account_external_id' => 'school1'])
            ->assertOk()->json('connection_id');

        $this->assertSame((string) $active->id, $reused);
        $this->assertSame(1, Connection::where('account_id', $account->id)->where('provider', 'exact')->count());

        $fresh = $active->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNotNull($fresh->access_token); // oude tokens behouden tot callback
    }

    public function test_relink_of_revoked_connection_reuses_row_and_clears_revoked_at(): void
    {
        $consumer = Consumer::factory()->create();
        $account = $consumer->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);
        $revoked = Connection::factory()->forExact()->create([
            'account_id' => $account->id,
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $reused = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/exact/init', ['account_external_id' => 'school1'])
            ->assertOk()->json('connection_id');

        // Reconnect herbruikt dezelfde row en wist de revoke-markering, anders
        // blijft de connection na reconnect 'active' mét revoked_at en faalt een
        // volgende DELETE op de revoked-guard (404).
        $this->assertSame((string) $revoked->id, $reused);
        $fresh = $revoked->fresh();
        $this->assertNull($fresh->revoked_at);
        $this->assertSame('pending', $fresh->status);
        $this->assertSame(1, Connection::where('account_id', $account->id)->where('provider', 'exact')->count());
    }

    public function test_init_without_ability_returns_403(): void
    {
        $consumer = Consumer::factory()->create();
        $consumer->accounts()->create([
            'external_id' => 'school1',
            'display_name' => 'School 1',
        ]);
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_READ])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/exact/init', ['account_external_id' => 'school1'])
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

        $tokenA = $consumerA->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/v1/oauth/exact/init', ['account_external_id' => 'b-only'])
            ->assertNotFound();
    }
}
