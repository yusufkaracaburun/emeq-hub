<?php

namespace App\Integrations\OAuth\Testing;

use App\Models\Account;
use App\Models\Connection;
use App\Integrations\Contracts\OAuthFlow;

final class FakeOAuthFlow implements OAuthFlow
{
    /** @var array<string, int> */
    private array $callCounts = [
        'getAuthorizationUrl' => 0,
        'exchangeCode' => 0,
        'refreshToken' => 0,
        'revoke' => 0,
    ];

    public function getAuthorizationUrl(Account $account, array $scopes, string $state): string
    {
        $this->callCounts['getAuthorizationUrl']++;

        return 'https://fake.oauth.local/authorize?state='.$state;
    }

    public function exchangeCode(Connection $connection, string $code): Connection
    {
        $this->callCounts['exchangeCode']++;

        $nonce = bin2hex(random_bytes(8));

        $connection->fill([
            'access_token' => "access_test_fake_{$nonce}",
            'refresh_token' => "refresh_test_fake_{$nonce}",
            'expires_at' => now()->addHour(),
            'scopes' => ['payments.read', 'payments.write'],
            'status' => 'active',
            'oauth_state' => null,
            'oauth_state_expires_at' => null,
        ])->save();

        return $connection;
    }

    public function refreshToken(Connection $connection): Connection
    {
        $this->callCounts['refreshToken']++;

        $nonce = bin2hex(random_bytes(8));

        $connection->fill([
            'access_token' => "access_test_fake_{$nonce}",
            'expires_at' => now()->addHour(),
        ])->save();

        return $connection;
    }

    public function revoke(Connection $connection): void
    {
        $this->callCounts['revoke']++;

        $connection->update(['status' => 'revoked', 'revoked_at' => now()]);
    }

    public function wasCalled(string $method): int
    {
        return $this->callCounts[$method] ?? 0;
    }
}
