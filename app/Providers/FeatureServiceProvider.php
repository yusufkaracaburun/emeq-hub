<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

/**
 * Definieert globale kill-switch features per partner-provider.
 *
 * Per-provider feature-key shape: `provider-<key>-enabled`. Default = true (provider open).
 * Set kill-switch via Feature::deactivate(...) zonder code-deploy.
 * Zie .docs/decisions/feature-flags-pennant-kill-switch.md.
 *
 * Scope: null (globaal). Per-Consumer-scoping landt in v0.2.1 als de eerste use-case er om vraagt.
 */
final class FeatureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (array_keys(config('hub-providers', [])) as $provider) {
            Feature::define("provider-{$provider}-enabled", fn () => true);
        }
    }
}
