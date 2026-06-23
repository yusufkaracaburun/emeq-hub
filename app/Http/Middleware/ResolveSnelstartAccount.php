<?php

namespace App\Http\Middleware;

use App\Enums\Provider;
use App\Models\Connection;
use App\Services\Snelstart\HubSnelstartCredentialResolver;
use Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver;
use Emeq\SnelstartApi\Snelstart;

/**
 * Snelstart-pass-through-context (zie ResolveProviderAccount). Bindt
 * HubSnelstartCredentialResolver per-request en forget de Snelstart-singleton zodat
 * een al-geboot-singleton uit een eerdere request niet de oude resolver vasthoudt.
 *
 * Beslissingen in 05b-CONTEXT.md §<decisions> ### Resolver binding.
 */
class ResolveSnelstartAccount extends ResolveProviderAccount
{
    protected function provider(): Provider
    {
        return Provider::Snelstart;
    }

    protected function connectionLabel(): string
    {
        return 'Snelstart';
    }

    protected function bindSdk(Connection $connection): void
    {
        app()->instance(
            SnelstartCredentialResolver::class,
            new HubSnelstartCredentialResolver($connection),
        );

        app()->forgetInstance(Snelstart::class);
    }
}
