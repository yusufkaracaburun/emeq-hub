<?php

namespace App\Integrations\Exact\OAuth;

use App\Integrations\Contracts\OAuthFlow;
use App\Integrations\Exact\ConnectionTokenStore;
use App\Integrations\Exact\ExactUserId;
use App\Integrations\Exact\HubExactCredentialResolver;
use App\Integrations\Exact\Jobs\DeleteExactWebhookSubscriptionsJob;
use App\Integrations\Exact\Jobs\RegisterExactWebhookSubscriptionsJob;
use App\Integrations\Exact\Jobs\SyncExactReferenceJob;
use App\Models\Account;
use App\Models\Connection;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\OData\Envelope;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

final class ExactOAuthFlow implements OAuthFlow
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
    ) {}

    public function getAuthorizationUrl(Account $account, array $scopes, string $state): string
    {
        $query = [
            'client_id' => $this->config->get('services.exact.client_id'),
            'redirect_uri' => $this->config->get('services.exact.redirect_uri'),
            'response_type' => 'code',
            'state' => $state,
        ];

        if ($scopes !== []) {
            $query['scope'] = implode(' ', $scopes);
        }

        return $this->authBaseUrl().'/api/oauth2/auth?'.http_build_query($query);
    }

    public function exchangeCode(Connection $connection, string $code): Connection
    {
        $token = $this->http->asForm()->post($this->tokenUrl(), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config->get('services.exact.redirect_uri'),
            'client_id' => $this->config->get('services.exact.client_id'),
            'client_secret' => $this->config->get('services.exact.client_secret'),
        ])->throw()->json();

        $me = $this->fetchMe((string) $token['access_token']);

        $metadata = $connection->metadata ?? [];
        if ($me['user_id'] !== null) {
            $metadata['exact_user_id'] = ExactUserId::normalize($me['user_id']);
        }

        $connection->fill([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expires_at' => now()->addSeconds((int) $token['expires_in']),
            'administratie_id' => $me['division'],
            'metadata' => $metadata,
            'status' => 'active',
            'revoked_at' => null,
            'oauth_state' => null,
            'oauth_state_expires_at' => null,
        ])->save();

        RegisterExactWebhookSubscriptionsJob::dispatch($connection);

        SyncExactReferenceJob::dispatch($connection);

        return $connection;
    }

    public function refreshToken(Connection $connection): Connection
    {
        app()->instance(ExactCredentialResolver::class, new HubExactCredentialResolver($connection));
        app()->instance(TokenStore::class, new ConnectionTokenStore($connection));
        app()->forgetInstance(Exact::class);

        /** @var Exact $exact */
        $exact = app(Exact::class);

        $exact->authenticator()->forceRefresh();

        return $connection->refresh();
    }

    public function revoke(Connection $connection): void
    {
        DeleteExactWebhookSubscriptionsJob::dispatch($connection);

        $connection->update(['status' => 'revoked', 'revoked_at' => now()]);
    }

    public function syncUserId(Connection $connection): bool
    {
        $connection = $this->refreshToken($connection);

        $userId = $this->fetchMe((string) $connection->access_token)['user_id'];

        if ($userId === null) {
            return false;
        }

        $metadata = $connection->metadata ?? [];
        $metadata['exact_user_id'] = ExactUserId::normalize($userId);

        $connection->update(['metadata' => $metadata]);

        return true;
    }

    /** @return array{division: ?string, user_id: ?string} */
    private function fetchMe(string $accessToken): array
    {
        $response = $this->http->withToken($accessToken)->acceptJson()
            ->get($this->apiBaseUrl().'/api/v1/current/Me');

        if ($response->failed()) {
            return ['division' => null, 'user_id' => null];
        }

        $me = Envelope::results($response->json())[0] ?? [];
        $division = $me['CurrentDivision'] ?? null;
        $userId = $me['UserID'] ?? null;

        return [
            'division' => $division !== null ? (string) $division : null,
            'user_id' => $userId !== null ? (string) $userId : null,
        ];
    }

    private function tokenUrl(): string
    {
        return $this->authBaseUrl().'/api/oauth2/token';
    }

    private function authBaseUrl(): string
    {
        return rtrim((string) $this->config->get('services.exact.auth_base_url'), '/');
    }

    private function apiBaseUrl(): string
    {
        return rtrim((string) $this->config->get('services.exact.api_base_url', $this->config->get('services.exact.auth_base_url')), '/');
    }
}
