<?php

declare(strict_types=1);

namespace App\Integrations\Exact;

use App\Models\Connection;
use DateTimeImmutable;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Data\AccessToken;
use Emeq\ExactApi\Data\ExactCredentials;
use Illuminate\Support\Facades\Log;

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
            : new DateTimeImmutable('-1 second');

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
