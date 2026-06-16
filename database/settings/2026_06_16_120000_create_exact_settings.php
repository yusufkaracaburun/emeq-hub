<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Seed defaults uit de huidige .env zodat niets breekt na migrate; daarna
        // beheer je ze via de admin. Secrets encrypted at rest.
        $this->migrator->add('exact.client_id', (string) env('EXACT_CLIENT_ID', ''));
        $this->migrator->addEncrypted('exact.client_secret', (string) env('EXACT_CLIENT_SECRET', ''));
        $this->migrator->add('exact.redirect_uri', (string) env('EXACT_REDIRECT_URI', ''));
        $this->migrator->addEncrypted('exact.webhook_secret', (string) env('EXACT_WEBHOOK_SECRET', ''));
    }
};
