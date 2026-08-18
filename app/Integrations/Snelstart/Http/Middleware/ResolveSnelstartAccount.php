<?php

namespace App\Integrations\Snelstart\Http\Middleware;

use App\Enums\Provider;
use App\Http\Middleware\ResolveProviderAccount;
use App\Integrations\Snelstart\HubSnelstartCredentialResolver;
use App\Models\Connection;
use Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver;
use Emeq\SnelstartApi\Snelstart;

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
