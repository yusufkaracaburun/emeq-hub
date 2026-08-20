<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

final class FeatureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (config('hub-providers', []) as $provider => $settings) {
            $enabled = (bool) ($settings['enabled'] ?? false);

            Feature::define("provider-{$provider}-enabled", fn (): bool => $enabled);
        }
    }
}
