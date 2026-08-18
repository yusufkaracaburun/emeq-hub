<?php

declare(strict_types=1);

namespace App\Integrations\Exact;

use App\Models\Connection;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Data\ExactCredentials;

final readonly class HubExactCredentialResolver implements ExactCredentialResolver
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function resolve(): ExactCredentials
    {
        return new ExactCredentials(
            clientId: (string) config('services.exact.client_id'),
            clientSecret: (string) config('services.exact.client_secret'),
            redirectUri: (string) config('services.exact.redirect_uri'),
            connectionRef: (string) $this->connection->id,
        );
    }
}
