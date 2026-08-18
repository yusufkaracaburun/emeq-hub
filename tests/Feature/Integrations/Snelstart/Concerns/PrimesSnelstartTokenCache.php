<?php

namespace Tests\Feature\Integrations\Snelstart\Concerns;

use App\Models\Connection;
use DateTimeImmutable;
use Emeq\SnelstartApi\Contracts\TokenCacheStore;
use Emeq\SnelstartApi\Data\AccessToken;
use Emeq\SnelstartApi\Data\SnelstartCredentials;

trait PrimesSnelstartTokenCache
{
    protected function primeSnelstartToken(Connection $connection, string $accessToken = 'fake-test-bearer'): void
    {
        /** @var TokenCacheStore $cache */
        $cache = app(TokenCacheStore::class);

        $credentials = new SnelstartCredentials(
            clientKey: (string) $connection->client_key,
            subscriptionKey: (string) $connection->subscription_key,
            subscriptionId: $connection->subscription_id,
        );

        $cache->put(
            $credentials,
            new AccessToken(
                accessToken: $accessToken,
                expiresAt: (new DateTimeImmutable)->modify('+1 hour'),
            ),
        );
    }
}
