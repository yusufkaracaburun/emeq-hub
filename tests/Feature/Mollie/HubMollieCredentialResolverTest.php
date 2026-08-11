<?php

namespace Tests\Feature\Mollie;

use App\Integrations\Mollie\HubMollieCredentialResolver;
use App\Integrations\Mollie\MollieConnectionContext;
use App\Integrations\Mollie\OAuth\MollieConnectOAuthFlow;
use App\Integrations\OAuth\Testing\FakeOAuthFlow;
use App\Models\Connection;
use Emeq\MollieApi\Data\MollieOAuthCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class HubMollieCredentialResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_returns_fresh_token_when_not_near_expiry(): void
    {
        $fake = new FakeOAuthFlow;
        $this->app->instance(MollieConnectOAuthFlow::class, $fake);

        $connection = Connection::factory()->forMollie()->active()->create([
            'access_token' => 'access_test_freshxyz',
            'expires_at' => now()->addHour(),
        ]);

        $this->app->make(MollieConnectionContext::class)->set($connection);

        $creds = $this->app->make(HubMollieCredentialResolver::class)->resolve();

        $this->assertInstanceOf(MollieOAuthCredentials::class, $creds);
        $this->assertSame('access_test_freshxyz', $creds->accessToken);
        $this->assertSame(0, $fake->wasCalled('refreshToken'));
    }

    public function test_resolve_triggers_refresh_when_within_five_minute_window(): void
    {
        $fake = new FakeOAuthFlow;
        $this->app->instance(MollieConnectOAuthFlow::class, $fake);

        $connection = Connection::factory()->forMollie()->active()->create([
            'access_token' => 'access_test_aboutoexpire',
            'expires_at' => now()->addMinutes(2),
        ]);

        $this->app->make(MollieConnectionContext::class)->set($connection);

        $creds = $this->app->make(HubMollieCredentialResolver::class)->resolve();

        $this->assertSame(1, $fake->wasCalled('refreshToken'));
        $this->assertStringStartsWith('access_test_fake_', $creds->accessToken);
    }

    public function test_resolve_throws_when_context_has_no_connection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('geen current Connection');

        $this->app->make(HubMollieCredentialResolver::class)->resolve();
    }
}
