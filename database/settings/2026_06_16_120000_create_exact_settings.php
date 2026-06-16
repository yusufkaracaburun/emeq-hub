<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('exact.client_id', '');
        $this->migrator->addEncrypted('exact.client_secret', '');
        $this->migrator->add('exact.redirect_uri', '');
        $this->migrator->addEncrypted('exact.webhook_secret', '');
        $this->migrator->add('exact.auth_base_url', 'https://start.exactonline.nl');
        $this->migrator->add('exact.api_base_url', 'https://start.exactonline.nl');
    }
};
