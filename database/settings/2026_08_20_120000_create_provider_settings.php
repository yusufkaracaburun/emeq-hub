<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $enabled = [];

        foreach (config('hub-providers', []) as $provider => $settings) {
            $enabled[$provider] = (bool) ($settings['enabled'] ?? false);
        }

        $this->migrator->add('providers.enabled', $enabled);
    }
};
