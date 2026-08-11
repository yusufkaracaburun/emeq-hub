<?php

namespace Tests\Feature\Api\OAuth;

use App\Integrations\Mollie\OAuth\MollieConnectOAuthFlow;
use App\Integrations\OAuth\Testing\FakeOAuthFlow;
use App\Models\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(MollieConnectOAuthFlow::class, FakeOAuthFlow::class);
    }

    public function test_callback_exchanges_code_when_state_matches(): void
    {
        $connection = Connection::factory()->forMollie()->pending()->create();
        $state = $connection->oauth_state;

        $this->getJson("/v1/oauth/mollie/callback?code=auth_code_xyz&state={$state}")
            ->assertOk()
            ->assertJson(['status' => 'active']);

        $connection->refresh();
        $this->assertSame('active', $connection->status);
        $this->assertNull($connection->oauth_state);
        $this->assertStringStartsWith('access_test_fake_', $connection->access_token);
    }

    public function test_callback_with_invalid_state_returns_400(): void
    {
        Connection::factory()->forMollie()->pending()->create();

        $this->getJson('/v1/oauth/mollie/callback?code=x&state=tampered_state')
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid_or_expired_state']);
    }

    public function test_callback_with_expired_state_returns_400(): void
    {
        $connection = Connection::factory()->forMollie()->pending()->expired()->create();

        $this->getJson("/v1/oauth/mollie/callback?code=x&state={$connection->oauth_state}")
            ->assertStatus(400);
    }

    public function test_second_callback_with_same_state_returns_400(): void
    {
        $connection = Connection::factory()->forMollie()->pending()->create();
        $state = $connection->oauth_state;

        $this->getJson("/v1/oauth/mollie/callback?code=x&state={$state}")->assertOk();
        $this->getJson("/v1/oauth/mollie/callback?code=x&state={$state}")->assertStatus(400);
    }
}
