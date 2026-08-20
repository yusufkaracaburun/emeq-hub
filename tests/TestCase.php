<?php

namespace Tests;

use App\Settings\ProviderSettings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $_ENV['APP_BASE_PATH'] = $_SERVER['APP_BASE_PATH'] = dirname(__DIR__);

        return parent::createApplication();
    }

    protected function disableProvider(string $provider): void
    {
        $settings = app(ProviderSettings::class);
        $enabled = $settings->enabled;
        $enabled[$provider] = false;
        $settings->enabled = $enabled;
        $settings->save();
    }
}
