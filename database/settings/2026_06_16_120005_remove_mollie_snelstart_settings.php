<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Settings-scope teruggebracht naar alleen Exact. Verwijdert de eerder geseede
 * Mollie/Snelstart-settings-rows. Veilig via `php artisan migrate` (geen data-wipe);
 * deleteIfExists is no-op op een verse DB.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        foreach ([
            'mollie.connect_client_id',
            'mollie.connect_client_secret',
            'mollie.connect_redirect_uri',
            'mollie.partner_access_token',
            'snelstart.webhook_secret',
        ] as $property) {
            $this->migrator->deleteIfExists($property);
        }
    }
};
