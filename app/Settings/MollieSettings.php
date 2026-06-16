<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Mollie Connect app-level OAuth-credentials (symmetrisch met ExactSettings).
 * connect_client_secret encrypted. Hydrateert config('services.mollie.connect.*').
 *
 * Scope: alleen de OAuth-app-creds. partner_access_token + cashier-webhook-secret
 * blijven voorlopig in .env (entangled met Cashier) — follow-up.
 */
class MollieSettings extends Settings
{
    public string $connect_client_id;

    public string $connect_client_secret;

    public string $connect_redirect_uri;

    public static function group(): string
    {
        return 'mollie';
    }

    /**
     * @return list<string>
     */
    public static function encrypted(): array
    {
        return ['connect_client_secret'];
    }
}
