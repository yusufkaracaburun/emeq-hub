<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('itheorie.username', '');
        $this->migrator->addEncrypted('itheorie.password', '');
        $this->migrator->add('itheorie.reseller', '');
        $this->migrator->add('itheorie.base_url', 'https://itheorie.nl/api/connect');
    }
};
