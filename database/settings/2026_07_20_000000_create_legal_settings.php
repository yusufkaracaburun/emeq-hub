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

        $this->migrator->add('legal.terms_statement', LegalDefaults::termsStatement());
        $this->migrator->add('legal.terms_updated_at', LegalDefaults::UPDATED_AT);

        $this->migrator->add('legal.dpa_statement', LegalDefaults::processorAgreement());
        $this->migrator->add('legal.dpa_updated_at', LegalDefaults::UPDATED_AT);
    }
};
