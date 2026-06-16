<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Mollie Connect OAuth-credentials + partner-access-token. Secrets encrypted.
 * Hydrateert config('services.mollie.connect.*') + services.mollie.partner_access_token.
 *
 * cashier-webhook-secret (CASHIER_WEBHOOK_SECRET) blijft voorlopig in .env
 * (entangled met Cashier) — follow-up.
 */
class MollieSettings extends Settings
{
    public string $connect_client_id;

    public string $connect_client_secret;

    public string $connect_redirect_uri;

    public string $partner_access_token;

    public static function group(): string
    {
        return 'mollie';
    }

    /**
     * @return list<string>
     */
    public static function encrypted(): array
    {
        return ['connect_client_secret', 'partner_access_token'];
    }
}
