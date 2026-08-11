<?php

declare(strict_types=1);

use App\Support\LegalDefaults;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * De drie juridische teksten die de Hub publiceert: privacyverklaring,
 * algemene voorwaarden en verwerkersovereenkomst.
 *
 * De teksten zelf leven in {@see LegalDefaults}; deze migratie zet alleen de
 * eerste waarde. Wijzigt een tekst, dan is dat een wijziging in LegalDefaults —
 * bestaande omgevingen halen 'm op via het admin-paneel, een verse omgeving
 * krijgt hem hier.
 */
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
