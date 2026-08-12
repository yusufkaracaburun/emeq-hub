<?php

declare(strict_types=1);

namespace Tests\Feature\OAuth;

use App\Integrations\Exact\OAuth\ExactOAuthFlow;
use App\Integrations\Mollie\OAuth\MollieConnectOAuthFlow;
use App\Integrations\OAuth\Testing\FakeOAuthFlow;
use App\Models\Connection;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class OAuthReturnToConsumerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(ExactOAuthFlow::class, FakeOAuthFlow::class);
        $this->app->bind(MollieConnectOAuthFlow::class, FakeOAuthFlow::class);
    }

    public function test_exact_init_persists_same_host_return_url(): void
    {
        $consumer = Consumer::factory()->withAppUrl('https://consumer.test')->create();
        $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'School 1']);
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/exact/init', [
                'account_external_id' => 'school1',
                'return_url' => 'https://consumer.test/integraties/klaar',
            ])
            ->assertOk();

        $this->assertDatabaseHas('connections', [
            'provider' => 'exact',
            'oauth_return_url' => 'https://consumer.test/integraties/klaar',
        ]);
    }

    public function test_exact_init_rejects_foreign_host_and_uses_app_url(): void
    {
        $consumer = Consumer::factory()->withAppUrl('https://consumer.test')->create();
        $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'School 1']);
        $token = $consumer->createToken('t', [TokenAbilities::EXACT_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/exact/init', [
                'account_external_id' => 'school1',
                'return_url' => 'https://evil.test/steal',
            ])
            ->assertOk();

        $this->assertDatabaseHas('connections', [
            'provider' => 'exact',
            'oauth_return_url' => 'https://consumer.test',
        ]);
    }

    public function test_mollie_init_persists_return_url(): void
    {
        $consumer = Consumer::factory()->withAppUrl('https://consumer.test')->create();
        $consumer->accounts()->create(['external_id' => 'school1', 'display_name' => 'School 1']);
        $token = $consumer->createToken('t', [TokenAbilities::MOLLIE_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/mollie/init', [
                'account_external_id' => 'school1',
                'return_url' => 'https://consumer.test/done',
            ])
            ->assertOk();

        $this->assertDatabaseHas('connections', [
            'provider' => 'mollie',
            'oauth_return_url' => 'https://consumer.test/done',
        ]);
    }

    public function test_connected_landing_links_back_to_consumer_app(): void
    {
        $connection = Connection::factory()->forExact()->create([
            'status' => 'active',
            'oauth_return_url' => 'https://consumer.test/integraties/klaar',
        ]);

        $url = URL::temporarySignedRoute('oauth.connected', now()->addMinutes(10), [
            'connection' => $connection->id,
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Terug naar de app')
            ->assertSee('https://consumer.test/integraties/klaar')
            ->assertSee('http-equiv="refresh"', false)
            ->assertDontSee('Terug naar Connections');
    }

    public function test_connected_landing_falls_back_to_hub_for_admin_flow(): void
    {
        $connection = Connection::factory()->forExact()->create([
            'status' => 'active',
            'oauth_return_url' => null,
        ]);

        $url = URL::temporarySignedRoute('oauth.connected', now()->addMinutes(10), [
            'connection' => $connection->id,
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Terug naar Connections')
            ->assertDontSee('Terug naar de app')
            ->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_failed_landing_links_back_to_consumer_app(): void
    {
        $url = URL::temporarySignedRoute('oauth.failed', now()->addMinutes(10), [
            'provider' => 'exact',
            'reason' => 'exchange_failed',
            'return_url' => 'https://consumer.test/integraties/mislukt',
        ]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Opnieuw proberen')
            ->assertSee('https://consumer.test/integraties/mislukt');
    }
}
