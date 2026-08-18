<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Settings;

use Spatie\LaravelSettings\Settings;

class ExactSettings extends Settings
{
    public string $client_id;

    public string $client_secret;

    public string $redirect_uri;

    public string $webhook_secret;

    public string $auth_base_url;

    public string $api_base_url;

    public static function group(): string
    {
        return 'exact';
    }

    /** @return list<string> */
    public static function encrypted(): array
    {
        return ['client_secret', 'webhook_secret'];
    }
}
