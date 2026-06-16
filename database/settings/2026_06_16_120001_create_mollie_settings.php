<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Mollie Connect OAuth-app-creds. De client-id heeft Mollie's `app_`-prefix;
        // in deze setup staan ze onder MOLLIE_PARTNER_* (CONNECT als fallback-naam).
        // Redirect default = de Hub's eigen OAuth-callback-route.
        $this->migrator->add('mollie.connect_client_id', (string) (env('MOLLIE_CONNECT_CLIENT_ID') ?: env('MOLLIE_PARTNER_CLIENT_ID', '')));
        $this->migrator->addEncrypted('mollie.connect_client_secret', (string) (env('MOLLIE_CONNECT_CLIENT_SECRET') ?: env('MOLLIE_PARTNER_CLIENT_SECRET', '')));
        $this->migrator->add('mollie.connect_redirect_uri', (string) (env('MOLLIE_CONNECT_REDIRECT_URI') ?: 'https://hub.emeq.nl/v1/oauth/mollie/callback'));
    }
};
