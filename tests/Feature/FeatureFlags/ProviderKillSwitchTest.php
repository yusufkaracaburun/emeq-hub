<?php

declare(strict_types=1);

namespace Tests\Feature\FeatureFlags;

use App\Models\Account;
use App\Models\Consumer;
use App\OAuth\Exceptions\ProviderDisabledException;
use App\OAuth\OAuthFlowRegistry;
use App\Sanctum\TokenAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
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

    public function test_oauth_flow_registry_throws_provider_disabled_when_feature_inactive(): void
    {
        $this->killProvider('mollie');

        /** @var OAuthFlowRegistry $registry */
        $registry = app(OAuthFlowRegistry::class);

        $this->expectException(ProviderDisabledException::class);
        $this->expectExceptionMessage("Provider 'mollie' is uitgeschakeld via feature-flag.");

        $registry->for('mollie');
    }

    public function test_killing_mollie_does_not_affect_snelstart(): void
    {
        $this->killProvider('mollie');

        [, $token] = $this->consumerWithToken([TokenAbilities::SNELSTART_READ]);

        // Snelstart-call hoort niet door de Mollie-kill-switch te worden geblokkeerd.
        // We krijgen 400 (missing_account_header) ipv 503 (provider_disabled).
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/v1/snelstart/echo/ping')
            ->assertStatus(400)
            ->assertJsonPath('error', 'missing_account_header');
    }

    public function test_enabled_provider_does_not_return_503(): void
    {
        // Default-state: beide providers actief. Mollie-pass-through faalt op
        // missing-account-header met 400, niet 503. Bewijst dat middleware
        // alleen blokkeert wanneer feature inactive is.
        [, $token] = $this->consumerWithToken([TokenAbilities::MOLLIE_WRITE]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/v1/mollie/payments', [])
            ->assertStatus(400)
            ->assertJsonPath('error', 'missing_account_header');
    }

    private function killProvider(string $provider): void
    {
        Feature::define("provider-{$provider}-enabled", fn () => false);
        Feature::flushCache();
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
