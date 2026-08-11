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
     * Provider-onafhankelijk, op één overgangspad na: tokens met `exact:*` houden
     * toegang. Die zijn uitgegeven toen `/v1/accounting/*` nog Exact-only was en
     * draaien nog bij bestaande consumers.
     *
     * Het pad noemt Exact expliciet en niet "de gekoppelde provider". Dat laatste
     * stond er, en zou betekenen dat elke nieuwe provider automatisch een
     * legacy-recht erft dat bij hem nooit heeft bestaan — een Moneybird-token met
     * `moneybird:write` zou dan de canonieke boek-endpoint openen zonder ooit
     * `accounting:write` te hebben gekregen.
     *
     * **Verwijderen zodra de bestaande consumers een `accounting:*`-token hebben.**
     * Daarna is deze methode een lijst van twee constanten en mag de allowlist weg.
     *
     * `*` wordt niet genoemd omdat Sanctum's `can()` daar zelf al op matcht.
     *
     * @return list<string>
     */
    public static function accounting(bool $write): array
    {
        if ($write) {
            return [self::ACCOUNTING_WRITE, self::EXACT_WRITE];
        }

        return [self::ACCOUNTING_READ, self::ACCOUNTING_WRITE, self::EXACT_READ, self::EXACT_WRITE];
    }
}
