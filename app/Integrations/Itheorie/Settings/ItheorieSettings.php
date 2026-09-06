<?php

declare(strict_types=1);

namespace App\Integrations\Itheorie\Settings;

use Spatie\LaravelSettings\Settings;

class ItheorieSettings extends Settings
{
    public string $environment;

    public string $username;

    public string $password;

    public string $base_url;

    public string $username_test;

    public string $password_test;

    public string $base_url_test;

    public string $reseller;

    public static function group(): string
    {
        return 'itheorie';
    }

    /** @return list<string> */
    public static function encrypted(): array
    {
        return ['password', 'password_test'];
    }
}
