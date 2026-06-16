<?php

namespace Tests\Feature\Api\OAuth;

use App\Models\Connection;
use App\OAuth\Exact\ExactOAuthFlow;
use App\OAuth\Testing\FakeOAuthFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExactCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(ExactOAuthFlow::class, FakeOAuthFlow::class);
    }

    private function pendingExactConnection(): Connection
    {
        return Connection::factory()->forExact()->create([
            'status' => 'pending',
            'oauth_state' => Str::random(48),
            'oauth_state_expires_at' => now()->addMinutes(30),
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'administratie_id' => null,
        ]);
    }

    public function test_callback_exchanges_code_when_state_matches(): void
    {
        $connection = $this->pendingExactConnection();
        $state = $connection->oauth_state;

        $this->getJson("/v1/oauth/exact/callback?code=auth_code_xyz&state={$state}")
            ->assertOk()
            ->assertJson(['status' => 'active']);

        $connection->refresh();
        $this->assertSame('active', $connection->status);
        $this->assertNull($connection->oauth_state);
        $this->assertStringStartsWith('access_test_fake_', $connection->access_token);
    }

    public function test_callback_with_invalid_state_returns_400(): void
    {
        $this->pendingExactConnection();

        $this->getJson('/v1/oauth/exact/callback?code=x&state=tampered_state')
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_or_expired_state']);
    }

    public function test_callback_with_expired_state_returns_400(): void
    {
        $connection = $this->pendingExactConnection();
        $connection->update(['oauth_state_expires_at' => now()->subMinute()]);

        $this->getJson("/v1/oauth/exact/callback?code=x&state={$connection->oauth_state}")
            ->assertStatus(400);
    }

    public function test_second_callback_with_same_state_returns_400(): void
    {
        $connection = $this->pendingExactConnection();
        $state = $connection->oauth_state;

        $this->getJson("/v1/oauth/exact/callback?code=x&state={$state}")->assertOk();
        $this->getJson("/v1/oauth/exact/callback?code=x&state={$state}")->assertStatus(400);
    }
}
