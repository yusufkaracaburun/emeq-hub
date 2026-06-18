<?php

namespace Tests\Feature\OAuth;

use App\Jobs\Exact\DeleteExactWebhookSubscriptionsJob;
use App\Jobs\Exact\RegisterExactWebhookSubscriptionsJob;
use App\Models\Account;
use App\Models\Connection;
use App\OAuth\Exact\ExactOAuthFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExactOAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.exact.client_id' => 'app_test_id',
            'services.exact.client_secret' => 'app_test_secret',
            'services.exact.redirect_uri' => 'https://hub.test/v1/oauth/exact/callback',
            'services.exact.auth_base_url' => 'https://start.exactonline.nl',
            'services.exact.api_base_url' => 'https://start.exactonline.nl',
        ]);
    }

    public function test_exchange_code_writes_tokens_and_division(): void
    {
        Bus::fake([RegisterExactWebhookSubscriptionsJob::class]);

        Http::fake([
            'start.exactonline.nl/api/oauth2/token' => Http::response([
                'access_token' => 'acc_xyz',
                'token_type' => 'bearer',
                'expires_in' => '600',
                'refresh_token' => 'ref_xyz',
            ]),
            'start.exactonline.nl/api/v1/current/Me' => Http::response([
                'd' => ['results' => [['CurrentDivision' => 4471372]]],
            ]),
        ]);

        $connection = Connection::factory()->forExact()->create([
            'status' => 'pending',
            'oauth_state' => 'st',
            'oauth_state_expires_at' => now()->addMinutes(30),
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'administratie_id' => null,
        ]);

        $this->app->make(ExactOAuthFlow::class)->exchangeCode($connection, 'auth_code_abc');

        $connection->refresh();
        $this->assertSame('active', $connection->status);
        $this->assertSame('acc_xyz', $connection->access_token);
        $this->assertSame('ref_xyz', $connection->refresh_token);
        $this->assertSame('4471372', $connection->administratie_id);
        $this->assertNull($connection->oauth_state);

        Bus::assertDispatched(
            RegisterExactWebhookSubscriptionsJob::class,
            fn (RegisterExactWebhookSubscriptionsJob $job): bool => $job->exactConnection->is($connection),
        );
    }

    public function test_get_authorization_url_contains_required_params_without_scope(): void
    {
        $url = $this->app->make(ExactOAuthFlow::class)->getAuthorizationUrl(
            Account::factory()->create(),
            [],
            'state_xyz',
        );

        $this->assertStringStartsWith('https://start.exactonline.nl/api/oauth2/auth?', $url);
        $this->assertStringContainsString('client_id=app_test_id', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('state=state_xyz', $url);
        $this->assertStringNotContainsString('scope=', $url);
    }

    public function test_refresh_is_skipped_while_token_still_valid(): void
    {
        Http::fake();

        $connection = Connection::factory()->forExact()->create([
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->app->make(ExactOAuthFlow::class)->refreshToken($connection);

        // Exact weigert refresh op een geldige token — dus we mogen niet refreshen.
        Http::assertNothingSent();
    }

    public function test_refresh_rotates_and_persists_new_refresh_token(): void
    {
        Http::fake([
            'start.exactonline.nl/api/oauth2/token' => Http::response([
                'access_token' => 'acc_new',
                'token_type' => 'bearer',
                'expires_in' => '600',
                'refresh_token' => 'ref_new',
            ]),
        ]);

        $connection = Connection::factory()->forExact()->create([
            'expires_at' => now()->subSecond(),
            'access_token' => 'acc_old',
            'refresh_token' => 'ref_old',
        ]);

        $this->app->make(ExactOAuthFlow::class)->refreshToken($connection);

        $connection->refresh();
        $this->assertSame('acc_new', $connection->access_token);
        $this->assertSame('ref_new', $connection->refresh_token);
        $this->assertNotSame('ref_old', $connection->refresh_token);
    }

    public function test_refresh_keeps_current_token_on_not_expired_refusal(): void
    {
        Http::fake([
            'start.exactonline.nl/api/oauth2/token' => Http::response([
                'error' => 'access_denied',
                'error_description' => 'Rate limit exceeded: access_token not expired',
            ], 400),
        ]);

        $connection = Connection::factory()->forExact()->create([
            'expires_at' => now()->subSecond(),
            'access_token' => 'acc_current',
            'refresh_token' => 'ref_current',
        ]);

        $this->app->make(ExactOAuthFlow::class)->refreshToken($connection);

        $connection->refresh();
        $this->assertSame('acc_current', $connection->access_token);
        $this->assertSame('ref_current', $connection->refresh_token);
    }

    public function test_revoke_marks_connection_revoked_locally_and_dispatches_unsubscribe(): void
    {
        Bus::fake([DeleteExactWebhookSubscriptionsJob::class]);
        Http::fake();

        $connection = Connection::factory()->forExact()->create(['status' => 'active']);

        $this->app->make(ExactOAuthFlow::class)->revoke($connection);

        $connection->refresh();
        $this->assertSame('revoked', $connection->status);
        $this->assertNotNull($connection->revoked_at);

        Bus::assertDispatched(
            DeleteExactWebhookSubscriptionsJob::class,
            fn (DeleteExactWebhookSubscriptionsJob $job): bool => $job->exactConnection->is($connection),
        );
    }
}
