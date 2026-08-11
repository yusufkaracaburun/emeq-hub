<?php

namespace App\Http\Middleware;

use App\Enums\Provider;
use App\Integrations\Exact\ConnectionTokenStore;
use App\Integrations\Exact\HubExactCredentialResolver;
use App\Models\Connection;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Exact;

/**
 * Exact-pass-through-context (zie ResolveProviderAccount). Bindt
 * HubExactCredentialResolver + ConnectionTokenStore per-request — de SDK leest beide
 * uit de container (TokenStore is verplicht: geen stille default) — en forget de
 * Exact-singleton zodat de nieuwe bindings effectief zijn.
 */
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
