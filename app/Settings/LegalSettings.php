<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class LegalSettings extends Settings
{
    public string $privacy_statement;

    public string $privacy_updated_at;

    public string $terms_statement;

    public string $terms_updated_at;

    public string $dpa_statement;

    public string $dpa_updated_at;

    public static function group(): string
    {
        return 'legal';
    }
}
