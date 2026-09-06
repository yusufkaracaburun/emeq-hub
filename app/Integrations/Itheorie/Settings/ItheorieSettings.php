<?php

declare(strict_types=1);

namespace App\Integrations\Itheorie\Settings;

use Spatie\LaravelSettings\Settings;

class ItheorieSettings extends Settings
{
    public string $username;

    public string $password;

    public string $reseller;

    public string $base_url;

    public static function group(): string
    {
        return 'itheorie';
    }

    /** @return list<string> */
    public static function encrypted(): array
    {
        return ['password'];
    }
}
