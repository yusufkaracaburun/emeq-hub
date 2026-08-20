<?php

declare(strict_types=1);

namespace Tests\Feature\FeatureFlags;

use App\Integrations\Exceptions\ProviderDisabledException;
use App\Integrations\OAuth\OAuthFlowRegistry;
use App\Models\Account;
use App\Models\Consumer;
use App\Sanctum\TokenAbilities;
use App\Settings\ProviderSettings;
use App\Support\Connect\ProviderConnectStatus;
use App\Support\ProviderGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderKillSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_mollie_disabled_returns_503_on_pass_through_call(): void
    {
        $this->killProvider('mollie');

        [, $token] = $this->consumerWithToken([TokenAbilities::MOLLIE_WRITE]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->postJson('/v1/mollie/payments', [])
            ->assertStatus(503)
            ->assertJsonPath('error', 'provider_disabled')
            ->assertJsonPath('provider', 'mollie');
    }

    public function test_snelstart_disabled_returns_503_on_pass_through_call(): void
    {
        $this->killProvider('snelstart');

        [, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Account-Id', 'school-A')
            ->getJson('/v1/snelstart/echo/ping')
            ->assertStatus(503)
            ->assertJsonPath('error', 'provider_disabled')
            ->assertJsonPath('provider', 'snelstart');
    }

    public function test_mollie_disabled_blocks_oauth_init(): void
    {
        $this->killProvider('mollie');

        $consumer = Consumer::factory()->create();
        Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        $token = $consumer->createToken('test', [TokenAbilities::MOLLIE_WRITE])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/oauth/mollie/init', ['account_external_id' => 'school-A'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'provider_disabled')
            ->assertJsonPath('provider', 'mollie');
    }

    public function test_oauth_flow_registry_throws_when_provider_is_off(): void
    {
        $this->killProvider('mollie');

        /** @var OAuthFlowRegistry $registry */
        $registry = app(OAuthFlowRegistry::class);

        $this->expectException(ProviderDisabledException::class);

        $registry->for('mollie');
    }

    public function test_killing_mollie_does_not_affect_snelstart(): void
    {
        $this->killProvider('mollie');

        [, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_READ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/snelstart/echo/ping')
            ->assertStatus(400)
            ->assertJsonPath('error', 'missing_account_header');
    }

    public function test_enabled_provider_does_not_return_503(): void
    {
        [, $token] = $this->consumerWithToken([TokenAbilities::MOLLIE_WRITE]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/mollie/payments', [])
            ->assertStatus(400)
            ->assertJsonPath('error', 'missing_account_header');
    }

    public function test_a_disabled_provider_is_not_connectable(): void
    {
        $this->killProvider('mollie');

        $mollie = collect(app(ProviderConnectStatus::class)->for(null))
            ->firstWhere('key', 'mollie');

        $this->assertNotNull($mollie);
        $this->assertFalse($mollie['connectable']);
    }

    public function test_a_provider_without_an_enabled_key_stays_disabled(): void
    {
        config()->set('hub-providers.mollie', ['oauth_flow_key' => 'mollie']);

        $settings = app(ProviderSettings::class);
        $settings->enabled = [];
        $settings->save();

        $this->assertFalse(ProviderGate::enabled('mollie'));
    }

    public function test_an_unknown_provider_is_disabled(): void
    {
        $this->assertFalse(ProviderGate::enabled('twinfield'));
    }

    public function test_the_stored_toggle_wins_over_the_config_default(): void
    {
        config()->set('hub-providers.mollie.enabled', true);

        $settings = app(ProviderSettings::class);
        $settings->enabled = ['mollie' => false];
        $settings->save();

        $this->assertFalse(ProviderGate::enabled('mollie'));
    }

    public function test_the_config_default_applies_when_nothing_is_stored(): void
    {
        config()->set('hub-providers.mollie.enabled', true);

        $settings = app(ProviderSettings::class);
        $settings->enabled = [];
        $settings->save();

        $this->assertTrue(ProviderGate::enabled('mollie'));
    }

    private function killProvider(string $provider): void
    {
        $settings = app(ProviderSettings::class);
        $enabled = $settings->enabled;
        $enabled[$provider] = false;
        $settings->enabled = $enabled;
        $settings->save();
    }

    /**
     * @param  list<string>  $abilities
     * @return array{0: Consumer, 1: string}
     */
    private function consumerWithToken(array $abilities): array
    {
        $consumer = Consumer::factory()->create();
        Account::factory()->for($consumer)->create(['external_id' => 'school-A']);
        $token = $consumer->createToken('test', $abilities)->plainTextToken;

        return [$consumer, $token];
    }
}
