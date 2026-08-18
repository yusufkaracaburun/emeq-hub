<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Integrations\Exact\Settings\ExactSettings;
use Database\Seeders\ExactDevSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExactDevSettingsSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{env: ?string, server: ?string, getenv: string|false}> */
    private array $envBackup = [];

    /** @param  array<string, string>  $vars */
    private function withEnv(array $vars): void
    {
        foreach ($vars as $key => $value) {
            $this->envBackup[$key] = [
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
                'getenv' => getenv($key),
            ];
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $orig) {
            if ($orig['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $orig['env'];
            }

            if ($orig['server'] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $orig['server'];
            }

            $orig['getenv'] === false ? putenv($key) : putenv("{$key}={$orig['getenv']}");
        }
        $this->envBackup = [];

        parent::tearDown();
    }

    public function test_hydrates_empty_settings_from_env(): void
    {
        $this->withEnv([
            'EXACT_CLIENT_ID' => 'env-cid',
            'EXACT_CLIENT_SECRET' => 'env-secret',
            'EXACT_REDIRECT_URI' => 'https://tunnel.test/v1/oauth/exact/callback',
            'EXACT_WEBHOOK_SECRET' => 'env-wh',
        ]);

        $this->seed(ExactDevSettingsSeeder::class);

        app()->forgetInstance(ExactSettings::class);
        $settings = app(ExactSettings::class);

        $this->assertSame('env-cid', $settings->client_id);
        $this->assertSame('env-secret', $settings->client_secret);
        $this->assertSame('https://tunnel.test/v1/oauth/exact/callback', $settings->redirect_uri);
        $this->assertSame('env-wh', $settings->webhook_secret);
    }

    public function test_does_not_overwrite_admin_entered_values(): void
    {
        $settings = app(ExactSettings::class);
        $settings->client_id = 'admin-set-cid';
        $settings->save();

        $this->withEnv(['EXACT_CLIENT_ID' => 'env-cid']);

        $this->seed(ExactDevSettingsSeeder::class);

        app()->forgetInstance(ExactSettings::class);

        $this->assertSame('admin-set-cid', app(ExactSettings::class)->client_id);
    }
}
