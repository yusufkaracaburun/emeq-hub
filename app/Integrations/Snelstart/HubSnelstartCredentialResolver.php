<?php

namespace App\Integrations\Snelstart;

use App\Models\Connection;
use Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver;
use Emeq\SnelstartApi\Data\SnelstartCredentials;

final readonly class HubSnelstartCredentialResolver implements SnelstartCredentialResolver
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function resolve(): SnelstartCredentials
    {
        return new SnelstartCredentials(
            clientKey: (string) $this->connection->client_key,
            subscriptionKey: (string) $this->connection->subscription_key,
            subscriptionId: $this->connection->subscription_id,
        );
    }
}
