<?php

declare(strict_types=1);

namespace App\Integrations\Exact;

use App\Models\Connection;
use DateTimeImmutable;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Data\AccessToken;
use Emeq\ExactApi\Data\ExactCredentials;

/**
 * SDK-TokenStore tegen een Hub-Connection. De SDK-OAuthAuthenticator leest/
 * schrijft hierdoor de encrypted tokens van precies één Connection (per-request
 * gebonden in ResolveExactAccount). Bij een refresh persisteert put() het
 * geroteerde refresh_token atomair op de Connection — Exact's single-use-token
 * vereist dat vóór de volgende API-call.
 */
final class ConnectionTokenStore implements TokenStore
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function get(ExactCredentials $credentials): ?AccessToken
    {
        if (empty($this->connection->access_token) || empty($this->connection->refresh_token)) {
            return null;
        }

        $expiresAt = $this->connection->expires_at !== null
            ? DateTimeImmutable::createFromInterface($this->connection->expires_at)
            : new DateTimeImmutable('-1 second'); // onbekende expiry → behandel als verlopen

        return new AccessToken(
            accessToken: (string) $this->connection->access_token,
            refreshToken: (string) $this->connection->refresh_token,
            expiresAt: $expiresAt,
        );
    }

    public function put(ExactCredentials $credentials, AccessToken $token): void
    {
        $this->connection->fill([
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at' => $token->expiresAt,
        ])->save();
    }
}
