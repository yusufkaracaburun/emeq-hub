<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->addEncrypted('mollie.partner_access_token', (string) env('MOLLIE_PARTNER_ACCESS_TOKEN', ''));
    }
};
