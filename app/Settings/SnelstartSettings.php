<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Snelstart app-level settings. Alleen het webhook-signing-secret is app-breed
 * (clientKey/subscriptionKey zijn per-Connection en al encrypted in de DB).
 * Hydrateert config('snelstart.webhook.secret') (SDK-config). Encrypted at rest.
 */
class SnelstartSettings extends Settings
{
    public string $webhook_secret;

    public static function group(): string
    {
        return 'snelstart';
    }

    /**
     * @return list<string>
     */
    public static function encrypted(): array
    {
        return ['webhook_secret'];
    }
}
