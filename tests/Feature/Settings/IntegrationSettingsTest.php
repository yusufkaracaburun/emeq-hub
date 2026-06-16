<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Providers\SettingsHydrationServiceProvider;
use App\Settings\ExactSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_settings_round_trip(): void
    {
        $settings = app(ExactSettings::class);
        $settings->client_id = 'cid-123';
        $settings->client_secret = 'secret-xyz';
        $settings->redirect_uri = 'https://hub.test/cb';
        $settings->webhook_secret = 'wh-abc';
        $settings->save();

        app()->forgetInstance(ExactSettings::class);
        $reloaded = app(ExactSettings::class);

        $this->assertSame('cid-123', $reloaded->client_id);
        $this->assertSame('secret-xyz', $reloaded->client_secret);
        $this->assertSame('wh-abc', $reloaded->webhook_secret);
    }

    public function test_secret_is_encrypted_at_rest(): void
    {
        $settings = app(ExactSettings::class);
        $settings->client_secret = 'plaintext-secret-zzz';
        $settings->save();

        $payload = DB::table('settings')
            ->where('group', 'exact')
            ->where('name', 'client_secret')
            ->value('payload');

        $this->assertStringNotContainsString('plaintext-secret-zzz', (string) $payload);
    }

    public function test_hydration_overrides_config_from_settings(): void
    {
        $settings = app(ExactSettings::class);
        $settings->client_id = 'hydrated-cid';
        $settings->save();

        (new SettingsHydrationServiceProvider($this->app))->boot();

        $this->assertSame('hydrated-cid', config('services.exact.client_id'));
    }

    public function test_hydration_falls_back_to_config_when_setting_empty(): void
    {
        // Expliciet leeg (de migratie seedt uit .env, die lokaal een echte
        // EXACT_CLIENT_ID kan bevatten) → lege setting valt terug op config/env.
        $settings = app(ExactSettings::class);
        $settings->client_id = '';
        $settings->save();

        config(['services.exact.client_id' => 'env-fallback']);

        (new SettingsHydrationServiceProvider($this->app))->boot();

        $this->assertSame('env-fallback', config('services.exact.client_id'));
    }
}
