<?php

namespace App\Services\Snelstart;

use App\Models\Connection;
use Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver;
use Emeq\SnelstartApi\Data\SnelstartCredentials;

/**
 * Per-Connection Snelstart credential-resolver. Bindt aan
 * Emeq\SnelstartApi\Contracts\SnelstartCredentialResolver via de container
 * in ResolveSnelstartAccount-middleware (Plan 05). Constructor neemt een
 * Snelstart-Connection; resolve() leest de decrypted waardes via de
 * Eloquent encrypted-casts en bouwt de DTO die de SDK consumeert.
 */
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
