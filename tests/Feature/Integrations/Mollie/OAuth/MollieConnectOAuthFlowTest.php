<?php

namespace Tests\Feature\Integrations\Mollie\OAuth;

use App\Integrations\Mollie\OAuth\MollieConnectOAuthFlow;
use App\Models\Account;
use App\Models\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MollieConnectOAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_exchange_code_writes_encrypted_tokens(): void
    {
        config(['services.mollie.connect.client_id' => 'app_test_id']);
        config(['services.mollie.connect.client_secret' => 'app_test_secret']);
        config(['services.mollie.connect.redirect_uri' => 'https://hub.test/v1/oauth/mollie/callback']);

        Http::fake([
            'api.mollie.com/oauth2/tokens' => Http::response([
                'access_token' => 'access_real_xyz',
                'refresh_token' => 'refresh_real_xyz',
                'expires_in' => 3600,
                'scope' => 'payments.read payments.write',
            ]),
        ]);

        $connection = Connection::factory()->forMollie()->pending()->create();

        $flow = $this->app->make(MollieConnectOAuthFlow::class);
        $flow->exchangeCode($connection, 'auth_code_abc');

        $connection->refresh();
        $this->assertSame('active', $connection->status);
        $this->assertSame('access_real_xyz', $connection->access_token);
        $this->assertContains('payments.write', $connection->scopes);
        $this->assertNull($connection->oauth_state);
    }

    public function test_exchange_code_clears_revoked_at_on_reconnect(): void
    {
        config(['services.mollie.connect.client_id' => 'app_test_id']);
        config(['services.mollie.connect.client_secret' => 'app_test_secret']);
        config(['services.mollie.connect.redirect_uri' => 'https://hub.test/v1/oauth/mollie/callback']);

        Http::fake([
            'api.mollie.com/oauth2/tokens' => Http::response([
                'access_token' => 'access_real_xyz',
                'refresh_token' => 'refresh_real_xyz',
                'expires_in' => 3600,
                'scope' => 'payments.read payments.write',
            ]),
        ]);

        $connection = Connection::factory()->forMollie()->create([
            'status' => 'revoked',
            'revoked_at' => now()->subDay(),
        ]);

        $this->app->make(MollieConnectOAuthFlow::class)->exchangeCode($connection, 'auth_code_abc');

        $connection->refresh();
        $this->assertSame('active', $connection->status);
        $this->assertNull($connection->revoked_at);
    }

    public function test_refresh_token_is_locked_per_connection(): void
    {
        $this->markTestIncomplete('Concurrent-refresh-race wordt getest in een aparte testcase met parallel-process simulatie.');
    }

    public function test_get_authorization_url_contains_required_query_params(): void
    {
        config(['services.mollie.connect.client_id' => 'app_test_id']);
        config(['services.mollie.connect.redirect_uri' => 'https://hub.test/v1/oauth/mollie/callback']);

        $flow = $this->app->make(MollieConnectOAuthFlow::class);
        $url = $flow->getAuthorizationUrl(
            Account::factory()->create(),
            ['payments.read'],
            'state_xyz',
        );

        $this->assertStringContainsString('client_id=app_test_id', $url);
        $this->assertStringContainsString('state=state_xyz', $url);
        $this->assertStringContainsString('scope=payments.read', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringStartsWith('https://my.mollie.com/oauth2/authorize?', $url);
    }
}
