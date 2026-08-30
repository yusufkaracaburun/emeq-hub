<?php

declare(strict_types=1);

namespace App\Providers;

use App\Integrations\Exact\Settings\ExactSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

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
                'exact.auth_base_url' => $exact->auth_base_url ?: config('services.exact.auth_base_url'),
                'exact.api_base_url' => $exact->api_base_url ?: config('services.exact.api_base_url'),
                'exact.webhook.secret' => $exact->webhook_secret ?: config('exact.webhook.secret'),
            ]);
        } catch (QueryException $e) {
            // Bevat alleen de connectiefout (host/poort/driver), geen query-bindings of
            // secrets — veilig om te loggen.
            Log::debug('SettingsHydrationServiceProvider: db onbereikbaar of driver ontbreekt, settings niet gehydrateerd.', [
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
