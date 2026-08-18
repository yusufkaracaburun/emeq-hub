<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

final class FeatureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (array_keys(config('hub-providers', [])) as $provider) {
            Feature::define("provider-{$provider}-enabled", fn () => true);
        }
    }
}
