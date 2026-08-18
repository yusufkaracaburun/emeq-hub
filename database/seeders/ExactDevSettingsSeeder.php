<?php

namespace Database\Seeders;

use App\Integrations\Exact\Settings\ExactSettings;
use Illuminate\Database\Seeder;

class ExactDevSettingsSeeder extends Seeder
{
    /** @var array<string, string> */
    private const ENV_MAP = [
        'client_id' => 'EXACT_CLIENT_ID',
        'client_secret' => 'EXACT_CLIENT_SECRET',
        'redirect_uri' => 'EXACT_REDIRECT_URI',
        'webhook_secret' => 'EXACT_WEBHOOK_SECRET',
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $settings = app(ExactSettings::class);
        $changed = false;

        foreach (self::ENV_MAP as $property => $envKey) {
            $value = (string) env($envKey, '');

            if ($settings->{$property} === '' && $value !== '') {
                $settings->{$property} = $value;
                $changed = true;
            }
        }

        if ($changed) {
            $settings->save();
            $this->command?->info('ExactDevSettingsSeeder: ExactSettings gehydrateerd uit .env.');
        }
    }
}
