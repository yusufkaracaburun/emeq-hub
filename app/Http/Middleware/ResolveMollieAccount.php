<?php

namespace App\Http\Middleware;

use App\Enums\Provider;
use App\Integrations\Mollie\MollieConnectionContext;
use App\Models\Connection;

/**
 * Mollie-pass-through-context (zie ResolveProviderAccount). Verschilt in de binding:
 * gebruikt MollieConnectionContext::set() i.p.v. een container-rebind, omdat
 * AppServiceProvider MollieConnectionContext als scoped binding registreert en
 * HubMollieCredentialResolver erop leest via constructor-injection. Geen
 * forgetInstance nodig — Mollie::client() bouwt elke call een verse client via resolve().
 *
 * Beslissingen in 05a-CONTEXT.md §<decisions> D-03.
 */
class ResolveMollieAccount extends ResolveProviderAccount
{
    protected function provider(): Provider
    {
        return Provider::Mollie;
    }

    protected function connectionLabel(): string
    {
        return 'Mollie';
    }

    protected function bindSdk(Connection $connection): void
    {
        app(MollieConnectionContext::class)->set($connection);
    }
}
