<?php

declare(strict_types=1);

use App\Support\LegalDefaults;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('legal.terms_statement', LegalDefaults::termsStatement());
        $this->migrator->add('legal.terms_updated_at', LegalDefaults::UPDATED_AT);

        // Ververs de privacyverklaring op reeds-geseede omgevingen naar de versie
        // mét bedrijfsgegevens (forward-only; de eerste seed had placeholders).
        $this->migrator->update('legal.privacy_statement', fn (): string => LegalDefaults::privacyStatement());
    }
};
