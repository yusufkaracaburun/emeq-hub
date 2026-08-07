<?php

declare(strict_types=1);

use App\Support\LegalDefaults;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Ververs de drie juridische teksten: Laravel Nightwatch uit de
        // sub-verwerkerslijst en partner-API's generiek benoemd (geen Exact/
        // Mollie/SnelStart bij naam). Forward-only.
        $this->migrator->update('legal.privacy_statement', fn (): string => LegalDefaults::privacyStatement());
        $this->migrator->update('legal.terms_statement', fn (): string => LegalDefaults::termsStatement());
        $this->migrator->update('legal.dpa_statement', fn (): string => LegalDefaults::processorAgreement());
    }
};
