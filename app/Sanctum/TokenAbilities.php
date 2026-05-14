<?php

namespace App\Sanctum;

final class TokenAbilities
{
    public const SNELSTART_READ = 'snelstart:read';

    public const SNELSTART_WRITE = 'snelstart:write';

    public const MOLLIE_READ = 'mollie:read';

    public const MOLLIE_WRITE = 'mollie:write';

    public const CONSUMER_MANAGE_ACCOUNTS = 'consumer:manage-accounts';

    public const ADMIN = '*';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SNELSTART_READ,
            self::SNELSTART_WRITE,
            self::MOLLIE_READ,
            self::MOLLIE_WRITE,
            self::CONSUMER_MANAGE_ACCOUNTS,
            self::ADMIN,
        ];
    }
}
