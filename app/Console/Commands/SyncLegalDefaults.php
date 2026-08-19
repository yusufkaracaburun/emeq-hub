<?php

namespace App\Console\Commands;

use App\Settings\LegalSettings;
use App\Support\LegalDefaults;
use Illuminate\Console\Command;

class SyncLegalDefaults extends Command
{
    protected $signature = 'legal:sync-defaults
                            {--dry-run : Toon per document hoeveel regels wijzigen zonder op te slaan}';

    protected $description = 'Schrijf de teksten uit App\Support\LegalDefaults naar de legal-settings (privacy, voorwaarden, verwerkersovereenkomst)';

    public function handle(LegalSettings $legal): int
    {
        $documents = [
            'privacy_statement' => LegalDefaults::privacyStatement(),
            'terms_statement' => LegalDefaults::termsStatement(),
            'dpa_statement' => LegalDefaults::processorAgreement(),
        ];

        $changed = [];

        foreach ($documents as $property => $markdown) {
            if ($legal->{$property} !== $markdown) {
                $changed[$property] = $this->lineDelta($legal->{$property}, $markdown);
            }
        }

        $dateChanged = $legal->privacy_updated_at !== LegalDefaults::UPDATED_AT;

        if ($changed === [] && ! $dateChanged) {
            $this->info('Legal-settings zijn al gelijk aan LegalDefaults.');

            return self::SUCCESS;
        }

        foreach ($changed as $property => $delta) {
            $this->line(sprintf('%s: %d regel(s) anders', $property, $delta));
        }

        if ($dateChanged) {
            $this->line(sprintf('datum: %s -> %s', $legal->privacy_updated_at, LegalDefaults::UPDATED_AT));
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry-run: niets opgeslagen.');

            return self::SUCCESS;
        }

        foreach ($documents as $property => $markdown) {
            $legal->{$property} = $markdown;
        }

        $legal->privacy_updated_at = LegalDefaults::UPDATED_AT;
        $legal->terms_updated_at = LegalDefaults::UPDATED_AT;
        $legal->dpa_updated_at = LegalDefaults::UPDATED_AT;
        $legal->save();

        $this->info('Legal-settings bijgewerkt.');

        return self::SUCCESS;
    }

    private function lineDelta(string $current, string $next): int
    {
        $before = explode("\n", $current);
        $after = explode("\n", $next);

        return count(array_diff($after, $before)) + count(array_diff($before, $after));
    }
}
