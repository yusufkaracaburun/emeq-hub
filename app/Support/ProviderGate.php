<?php

declare(strict_types=1);

namespace App\Support;

use App\Settings\ProviderSettings;
use Throwable;

class ProviderGate
{
    public static function enabled(string $provider): bool
    {
        $configured = config('hub-providers', []);

        if (! array_key_exists($provider, $configured)) {
            return false;
        }

        $default = (bool) ($configured[$provider]['enabled'] ?? false);

        try {
            $stored = app(ProviderSettings::class)->enabled;
        } catch (Throwable) {
            return $default;
        }

        return array_key_exists($provider, $stored)
            ? (bool) $stored[$provider]
            : $default;
    }
}
