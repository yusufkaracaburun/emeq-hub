<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class ProviderSettings extends Settings
{
    /** @var array<string, bool> */
    public array $enabled;

    public static function group(): string
    {
        return 'providers';
    }

    public function isEnabled(string $provider): bool
    {
        return (bool) ($this->enabled[$provider] ?? false);
    }
}
