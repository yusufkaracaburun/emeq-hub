<?php

declare(strict_types=1);

use App\Support\LegalDefaults;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Ververs beide teksten naar de laatste defaults (o.a. support@emeq.nl
        // i.p.v. info@). Forward-only; overschrijft de eerdere seed.
        $this->migrator->update('legal.privacy_statement', fn (): string => LegalDefaults::privacyStatement());
        $this->migrator->update('legal.terms_statement', fn (): string => LegalDefaults::termsStatement());
    }
};
