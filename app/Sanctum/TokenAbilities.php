<?php

namespace App\Sanctum;

final class TokenAbilities
{
    public const SNELSTART_READ = 'snelstart:read';

    public const SNELSTART_WRITE = 'snelstart:write';

    public const MOLLIE_READ = 'mollie:read';

    public const MOLLIE_WRITE = 'mollie:write';

    public const EXACT_READ = 'exact:read';

    public const EXACT_WRITE = 'exact:write';

    public const ACCOUNTING_READ = 'accounting:read';

    public const ACCOUNTING_WRITE = 'accounting:write';

    public const INTEGRATIONS_MANAGE = 'integrations:manage';

    public const CONSUMER_MANAGE_ACCOUNTS = 'consumer:manage-accounts';

    public const BILLING_READ = 'billing:read';

    public const BILLING_WRITE = 'billing:write';

    public const ADMIN = '*';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SNELSTART_READ,
            self::SNELSTART_WRITE,
            self::MOLLIE_READ,
            self::MOLLIE_WRITE,
            self::EXACT_READ,
            self::EXACT_WRITE,
            self::ACCOUNTING_READ,
            self::ACCOUNTING_WRITE,
            self::INTEGRATIONS_MANAGE,
            self::CONSUMER_MANAGE_ACCOUNTS,
            self::BILLING_READ,
            self::BILLING_WRITE,
            self::ADMIN,
        ];
    }

    /** @return list<string> */
    public static function accounting(bool $write): array
    {
        if ($write) {
            return [self::ACCOUNTING_WRITE, self::EXACT_WRITE];
        }

        return [self::ACCOUNTING_READ, self::ACCOUNTING_WRITE, self::EXACT_READ, self::EXACT_WRITE];
    }
}
