<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Exact Online app-level credentials, beheerd via de admin i.p.v. .env.
 * Secrets (client_secret, webhook_secret) worden encrypted opgeslagen.
 * SettingsHydrationServiceProvider hydrateert hiermee config('services.exact.*').
 */
class ExactSettings extends Settings
{
    public string $client_id;

    public string $client_secret;

    public string $redirect_uri;

    public string $webhook_secret;

    public static function group(): string
    {
        return 'exact';
    }

    /**
     * @return list<string>
     */
    public static function encrypted(): array
    {
        return ['client_secret', 'webhook_secret'];
    }
}
