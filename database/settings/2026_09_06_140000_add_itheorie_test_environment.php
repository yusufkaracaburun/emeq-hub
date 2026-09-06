<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('itheorie.environment', 'live');
        $this->migrator->add('itheorie.username_test', '');
        $this->migrator->addEncrypted('itheorie.password_test', '');
        $this->migrator->add('itheorie.base_url_test', 'https://test.itheorie.nl/api/connect');
    }
};
