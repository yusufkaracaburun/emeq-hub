<?php

namespace App\OAuth\Mollie;

use App\Models\Account;
use App\Models\Connection;
use App\OAuth\Contracts\OAuthFlow;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;

final class MollieConnectOAuthFlow implements OAuthFlow
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
    ) {}

    public function getAuthorizationUrl(Account $account, array $scopes, string $state): string
    {
        return 'https://my.mollie.com/oauth2/authorize?'.http_build_query([
            'client_id' => $this->config->get('services.mollie.connect.client_id'),
            'redirect_uri' => $this->config->get('services.mollie.connect.redirect_uri'),
            'state' => $state,
            'scope' => implode(' ', $scopes),
            'response_type' => 'code',
            'approval_prompt' => 'auto',
        ]);
    }

    public function exchangeCode(Connection $connection, string $code): Connection
    {
        $response = $this->http->asForm()->post('https://api.mollie.com/oauth2/tokens', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config->get('services.mollie.connect.redirect_uri'),
            'client_id' => $this->config->get('services.mollie.connect.client_id'),
            'client_secret' => $this->config->get('services.mollie.connect.client_secret'),
        ])->throw()->json();

        $connection->fill([
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'],
            'expires_at' => now()->addSeconds((int) $response['expires_in']),
            'scopes' => explode(' ', (string) ($response['scope'] ?? '')),
            'status' => 'active',
            'revoked_at' => null,
            'oauth_state' => null,
            'oauth_state_expires_at' => null,
        ])->save();

        return $connection;
    }

    public function refreshToken(Connection $connection): Connection
    {
        return Cache::lock("oauth:refresh:{$connection->id}", 30)->block(15, function () use ($connection) {
            $connection->refresh();

            if ($connection->expires_at && $connection->expires_at->gt(now()->addMinutes(5))) {
                return $connection;
            }

            $response = $this->http->asForm()->post('https://api.mollie.com/oauth2/tokens', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
                'client_id' => $this->config->get('services.mollie.connect.client_id'),
                'client_secret' => $this->config->get('services.mollie.connect.client_secret'),
            ])->throw()->json();

            $connection->fill([
                'access_token' => $response['access_token'],
                'refresh_token' => $response['refresh_token'] ?? $connection->refresh_token,
                'expires_at' => now()->addSeconds((int) $response['expires_in']),
            ])->save();

            return $connection;
        });
    }

    public function revoke(Connection $connection): void
    {
        $this->http->withBasicAuth(
            (string) $this->config->get('services.mollie.connect.client_id'),
            (string) $this->config->get('services.mollie.connect.client_secret'),
        )->delete('https://api.mollie.com/oauth2/tokens', [
            'token_type_hint' => 'access_token',
            'token' => $connection->access_token,
        ]);

        $connection->update(['status' => 'revoked', 'revoked_at' => now()]);
    }
}
