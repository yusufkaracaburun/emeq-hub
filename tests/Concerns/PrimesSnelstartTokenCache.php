<?php

namespace Tests\Concerns;

use App\Models\Connection;
use DateTimeImmutable;
use Emeq\SnelstartApi\Contracts\TokenCacheStore;
use Emeq\SnelstartApi\Data\AccessToken;
use Emeq\SnelstartApi\Data\SnelstartCredentials;

/**
 * Pre-fills de Snelstart-SDK token-cache zodat de ClientKeyAuthenticator
 * tijdens tests géén echte OAuth2-call probeert te doen.
 *
 * Saloon's MockClient::global vangt alleen de `RawSnelstartRequest`-class af;
 * de auth-flow via AuthConnector + ClientKeyOAuthRequest zou anders een echte
 * HTTP-call doen en falen → 502 in de Hub-response.
 */
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
