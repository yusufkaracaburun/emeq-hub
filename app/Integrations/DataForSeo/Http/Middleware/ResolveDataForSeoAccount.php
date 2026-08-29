<?php

declare(strict_types=1);

namespace App\Integrations\DataForSeo\Http\Middleware;

use App\Enums\Provider;
use App\Http\Middleware\ResolveProviderAccount;
use App\Integrations\DataForSeo\HubDataForSeoCredentialResolver;
use App\Models\Connection;
use Emeq\DataForSeoApi\Contracts\DataForSeoCredentialResolver;
use Emeq\DataForSeoApi\DataForSeo;

class ResolveDataForSeoAccount extends ResolveProviderAccount
{
    protected function provider(): Provider
    {
        return Provider::DataForSeo;
    }

    protected function connectionLabel(): string
    {
        return 'DataForSEO';
    }

    protected function bindSdk(Connection $connection): void
    {
        app()->instance(
            DataForSeoCredentialResolver::class,
            new HubDataForSeoCredentialResolver($connection),
        );

        app()->forgetInstance(DataForSeo::class);
    }
}
