<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->addEncrypted('snelstart.webhook_secret', (string) env('SNELSTART_WEBHOOK_SECRET', ''));
    }
};
