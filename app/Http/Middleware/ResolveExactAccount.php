<?php

namespace App\Http\Middleware;

use App\Enums\Provider;
use App\Integrations\Exact\ConnectionTokenStore;
use App\Integrations\Exact\HubExactCredentialResolver;
use App\Models\Connection;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Exact;

class ResolveExactAccount extends ResolveProviderAccount
{
    protected function provider(): Provider
    {
        return Provider::Exact;
    }

    protected function connectionLabel(): string
    {
        return 'Exact';
    }

    protected function bindSdk(Connection $connection): void
    {
        app()->instance(ExactCredentialResolver::class, new HubExactCredentialResolver($connection));
        app()->instance(TokenStore::class, new ConnectionTokenStore($connection));
        app()->forgetInstance(Exact::class);
    }
}
