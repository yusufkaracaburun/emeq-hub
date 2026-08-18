<?php

namespace Tests\Feature\Integrations\Exact\Http\OAuth;

use App\Integrations\Exact\OAuth\ExactOAuthFlow;
use App\Integrations\OAuth\Testing\FakeOAuthFlow;
use App\Models\Connection;
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

    public function test_callback_exchanges_code_and_redirects_to_connected(): void
    {
        $connection = $this->pendingExactConnection();
        $state = $connection->oauth_state;

        $this->get("/v1/oauth/exact/callback?code=auth_code_xyz&state={$state}")
            ->assertRedirectContains('/oauth/connected');

        $connection->refresh();
        $this->assertSame('active', $connection->status);
        $this->assertNull($connection->oauth_state);
        $this->assertStringStartsWith('access_test_fake_', $connection->access_token);
    }

    public function test_callback_with_invalid_state_redirects_to_failed(): void
    {
        $this->pendingExactConnection();

        $this->get('/v1/oauth/exact/callback?code=x&state=tampered_state')
            ->assertRedirectContains('/oauth/failed');
    }

    public function test_callback_with_expired_state_redirects_to_failed(): void
    {
        $connection = $this->pendingExactConnection();
        $connection->update(['oauth_state_expires_at' => now()->subMinute()]);

        $this->get("/v1/oauth/exact/callback?code=x&state={$connection->oauth_state}")
            ->assertRedirectContains('/oauth/failed');
    }

    public function test_second_callback_with_same_state_redirects_to_failed(): void
    {
        $connection = $this->pendingExactConnection();
        $state = $connection->oauth_state;

        $this->get("/v1/oauth/exact/callback?code=x&state={$state}")
            ->assertRedirectContains('/oauth/connected');
        $this->get("/v1/oauth/exact/callback?code=x&state={$state}")
            ->assertRedirectContains('/oauth/failed');
    }

    public function test_callback_with_provider_error_redirects_to_failed(): void
    {
        $this->pendingExactConnection();

        $this->get('/v1/oauth/exact/callback?error=access_denied&error_description=nope')
            ->assertRedirectContains('/oauth/failed');
    }
}
