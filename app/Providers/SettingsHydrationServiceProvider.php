<?php

declare(strict_types=1);

namespace App\Providers;

use App\Settings\ExactSettings;
use App\Settings\MollieSettings;
use App\Settings\SnelstartSettings;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Hydrateert config('services.*') uit de DB-settings, met .env als fallback.
 * Hierdoor blijven de SDK's, OAuthFlows en credential-resolvers ongewijzigd
 * (ze lezen gewoon config()), terwijl de waardes via de admin beheerd worden.
 *
 * Guard: vóór de settings-migratie (of in CI zonder DB) bestaat de tabel niet —
 * dan overslaan en op de env-config terugvallen. Lege settings → eveneens env.
 */
class SettingsHydrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        try {
            $exact = app(ExactSettings::class);
            config([
                'services.exact.client_id' => $exact->client_id ?: config('services.exact.client_id'),
                'services.exact.client_secret' => $exact->client_secret ?: config('services.exact.client_secret'),
                'services.exact.redirect_uri' => $exact->redirect_uri ?: config('services.exact.redirect_uri'),
                'services.exact.webhook_secret' => $exact->webhook_secret ?: config('services.exact.webhook_secret'),
                'services.exact.auth_base_url' => $exact->auth_base_url ?: config('services.exact.auth_base_url'),
                'services.exact.api_base_url' => $exact->api_base_url ?: config('services.exact.api_base_url'),
            ]);

            $mollie = app(MollieSettings::class);
            config([
                'services.mollie.connect.client_id' => $mollie->connect_client_id ?: config('services.mollie.connect.client_id'),
                'services.mollie.connect.client_secret' => $mollie->connect_client_secret ?: config('services.mollie.connect.client_secret'),
                'services.mollie.connect.redirect_uri' => $mollie->connect_redirect_uri ?: config('services.mollie.connect.redirect_uri'),
                'services.mollie.partner_access_token' => $mollie->partner_access_token ?: config('services.mollie.partner_access_token'),
            ]);

            $snelstart = app(SnelstartSettings::class);
            config([
                'snelstart.webhook.secret' => $snelstart->webhook_secret ?: config('snelstart.webhook.secret'),
            ]);
        } catch (Throwable $e) {
            // Settings nog niet geseed (rows ontbreken) e.d. → val terug op env-config.
            report($e);
        }
    }
}
