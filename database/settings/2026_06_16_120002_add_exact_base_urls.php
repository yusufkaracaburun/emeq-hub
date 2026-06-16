<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Region-base-URLs (geen secrets). Default NL.
        $this->migrator->add('exact.auth_base_url', (string) env('EXACT_AUTH_BASE_URL', 'https://start.exactonline.nl'));
        $this->migrator->add('exact.api_base_url', (string) env('EXACT_API_BASE_URL', 'https://start.exactonline.nl'));
    }
};
