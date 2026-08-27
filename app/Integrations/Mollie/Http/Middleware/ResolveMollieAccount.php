<?php

namespace App\Integrations\Mollie\Http\Middleware;

use App\Enums\Provider;
use App\Http\Middleware\ResolveProviderAccount;
use App\Integrations\Mollie\MollieConnectionContext;
use App\Models\Connection;

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
