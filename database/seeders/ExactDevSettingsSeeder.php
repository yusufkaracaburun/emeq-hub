<?php

namespace Database\Seeders;

use App\Settings\ExactSettings;
use Illuminate\Database\Seeder;

/**
 * Dev-only: spiegelt de Exact-app-credentials uit .env naar ExactSettings
 * wanneer een veld leeg is. `migrate:fresh` wist de DB-settings (prod blijft
 * DB-only, geen env-fallback) — deze seeder herstelt een werkende dev-koppeling
 * zonder de creds opnieuw in de admin te tikken. Alleen lege velden, zodat
 * admin-ingevoerde waarden niet worden overschreven.
 */
class ExactDevSettingsSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
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
