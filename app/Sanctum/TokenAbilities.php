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

    /**
     * Provider-onafhankelijk lezen en schrijven op `/v1/accounting/*`.
     *
     * De canonieke endpoints kiezen zelf welke provider gekoppeld is, dus een
     * consumer die daarop een `exact:write` moet houden verliest zijn token zodra
     * een eindgebruiker naar een ander boekhoudpakket verhuist. Deze twee zijn de
     * ability die bij het canonieke contract hoort; `{provider}:*` blijft gelden
     * voor de ruwe pass-through, die per definitie providerspecifiek is.
     */
    public const ACCOUNTING_READ = 'accounting:read';

    public const ACCOUNTING_WRITE = 'accounting:write';

    public const INTEGRATIONS_MANAGE = 'integrations:manage';

    public const CONSUMER_MANAGE_ACCOUNTS = 'consumer:manage-accounts';

    public const BILLING_READ = 'billing:read';

    public const BILLING_WRITE = 'billing:write';

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

    /**
     * De abilities die toegang geven tot een canoniek accounting-endpoint.
     *
     * Any-of: de canonieke ability óf de provider-ability van de gekoppelde provider.
     * Die tweede staat er zolang bestaande consumers nog op `{provider}:*`-tokens
     * draaien; hij mag weg zodra die vervangen zijn. `*` wordt niet genoemd omdat
     * Sanctum's `can()` daar zelf al op matcht.
     *
     * @return list<string>
     */
    public static function accounting(string $provider, bool $write): array
    {
        if ($write) {
            return [self::ACCOUNTING_WRITE, "{$provider}:write"];
        }

        return [self::ACCOUNTING_READ, self::ACCOUNTING_WRITE, "{$provider}:read", "{$provider}:write"];
    }
}
