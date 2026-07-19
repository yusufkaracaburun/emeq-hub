<?php

declare(strict_types=1);

use App\Support\LegalDefaults;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('legal.privacy_statement', LegalDefaults::privacyStatement());
        $this->migrator->add('legal.privacy_updated_at', LegalDefaults::UPDATED_AT);
    }
};
