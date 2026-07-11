<?php

declare(strict_types=1);

namespace App\Providers;

use App\Settings\ExactSettings;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Hydrateert config('services.exact.*') uit de DB-settings — dé bron voor de
 * Exact-credentials (config/services.php heeft geen env meer; creds = null,
 * base-URLs een statische default). SDK's, OAuthFlows en credential-resolvers
 * blijven ongewijzigd (ze lezen gewoon config()).
 *
 * Guard: vóór de settings-migratie (of zonder bereikbare DB) is er niets te
 * hydrateren — dan overslaan. Een lege setting valt via ?: terug op de
 * config-default (null voor creds, statische base-URL) — niet op .env.
 *
 * `Schema::hasTable()` staat bewust bínnen de try: die opent zélf een verbinding.
 * Is er geen database — zoals tijdens `artisan package:discover` in de
 * Docker-build — dan gooit die regel, en een gooiende provider laat de hele
 * image-build klappen.
 */
class SettingsHydrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $exact = app(ExactSettings::class);
            config([
                'services.exact.client_id' => $exact->client_id ?: config('services.exact.client_id'),
                'services.exact.client_secret' => $exact->client_secret ?: config('services.exact.client_secret'),
                'services.exact.redirect_uri' => $exact->redirect_uri ?: config('services.exact.redirect_uri'),
                'services.exact.webhook_secret' => $exact->webhook_secret ?: config('services.exact.webhook_secret'),
                'services.exact.auth_base_url' => $exact->auth_base_url ?: config('services.exact.auth_base_url'),
                'services.exact.api_base_url' => $exact->api_base_url ?: config('services.exact.api_base_url'),
                // De SDK-webhook-middleware (verify.exact.signature) leest zijn eigen
                // namespace exact.webhook.secret — vul die uit dezelfde DB-setting.
                'exact.webhook.secret' => $exact->webhook_secret ?: config('exact.webhook.secret'),
            ]);
        } catch (Throwable $e) {
            // Settings nog niet geseed (rows ontbreken) e.d. → val terug op env-config.
            report($e);
        }
    }
}
