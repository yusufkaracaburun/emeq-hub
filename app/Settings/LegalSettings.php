<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Publiek beheerbare juridische teksten (privacyverklaring). Beheerd via de
 * admin (ManageLegalPages) als markdown; de publieke /privacy-pagina rendert
 * het naar HTML. Géén secrets — dit is publieke content, niet encrypted.
 */
class LegalSettings extends Settings
{
    public string $privacy_statement;

    public string $privacy_updated_at;

    public static function group(): string
    {
        return 'legal';
    }
}
