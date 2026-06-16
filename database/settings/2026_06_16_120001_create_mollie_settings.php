<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Alleen de Mollie Connect OAuth-app-creds (symmetrisch met Exact).
        // partner_access_token + cashier-webhook-secret blijven in .env (follow-up).
        $this->migrator->add('mollie.connect_client_id', (string) env('MOLLIE_CONNECT_CLIENT_ID', ''));
        $this->migrator->addEncrypted('mollie.connect_client_secret', (string) env('MOLLIE_CONNECT_CLIENT_SECRET', ''));
        $this->migrator->add('mollie.connect_redirect_uri', (string) env('MOLLIE_CONNECT_REDIRECT_URI', ''));
    }
};
