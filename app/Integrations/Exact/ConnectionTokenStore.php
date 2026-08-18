<?php

declare(strict_types=1);

namespace App\Integrations\Exact;

use App\Models\Connection;
use DateTimeImmutable;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Data\AccessToken;
use Emeq\ExactApi\Data\ExactCredentials;
use Illuminate\Support\Facades\Log;

/**
 * SDK-TokenStore tegen een Hub-Connection. De SDK-OAuthAuthenticator leest/
 * schrijft hierdoor de encrypted tokens van precies één Connection (per-request
 * gebonden in ResolveExactAccount). Bij een refresh persisteert put() het
 * geroteerde refresh_token atomair op de Connection — Exact's single-use-token
 * vereist dat vóór de volgende API-call.
 *
 * put() draait uitsluitend bij een refresh: de initiële connect schrijft de
 * eerste tokenbundle rechtstreeks via ExactOAuthFlow::exchangeCode(), buiten
 * deze store om. Elke aanroep hier is dus een rotatie, nooit een eerste token.
 */
final class ConnectionTokenStore implements TokenStore
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function get(ExactCredentials $credentials): ?AccessToken
    {
        $row = Connection::query()
            ->whereKey($this->connection->getKey())
            ->first(['id', 'access_token', 'refresh_token', 'expires_at']);

        if ($row === null || empty($row->access_token) || empty($row->refresh_token)) {
            return null;
        }

        $expiresAt = $row->expires_at !== null
            ? DateTimeImmutable::createFromInterface($row->expires_at)
            : new DateTimeImmutable('-1 second'); // onbekende expiry → behandel als verlopen

        if ($expiresAt <= new DateTimeImmutable) {
            Log::info('exact.oauth.refresh_attempt_started', [
                'connection_id' => $this->connection->id,
                'observed_at' => now()->toIso8601String(),
                'refresh_token_fingerprint' => self::fingerprint((string) $row->refresh_token),
            ]);
        }

        return new AccessToken(
            accessToken: (string) $row->access_token,
            refreshToken: (string) $row->refresh_token,
            expiresAt: $expiresAt,
        );
    }

    public function put(ExactCredentials $credentials, AccessToken $token): void
    {
        $oldRefreshTokenFingerprint = self::fingerprint($this->connection->refresh_token);

        $this->connection->fill([
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at' => $token->expiresAt,
        ])->save();

        // Enige zichtbare spoor van een geslaagde rotatie — zonder deze regel is het
        // rotatiemoment onzichtbaar en blijft elke diagnose giswerk (#61).
        Log::info('exact.oauth.refresh_token_rotated', [
            'connection_id' => $this->connection->id,
            'rotated_at' => now()->toIso8601String(),
            'old_refresh_token_fingerprint' => $oldRefreshTokenFingerprint,
            'new_refresh_token_fingerprint' => self::fingerprint($token->refreshToken),
        ]);
    }

    private static function fingerprint(?string $token): ?string
    {
        return $token !== null && $token !== '' ? substr(hash('sha256', $token), 0, 12) : null;
    }
}
