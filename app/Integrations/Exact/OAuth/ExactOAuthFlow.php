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
use Emeq\ExactApi\Auth\AuthorizeUrlBuilder;
use Emeq\ExactApi\Contracts\ExactCredentialResolver;
use Emeq\ExactApi\Contracts\TokenStore;
use Emeq\ExactApi\Data\AuthorizeUrlParameters;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\Http\Request\Read\GetMe;
use Emeq\ExactApi\OData\Envelope;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

final class ExactOAuthFlow implements OAuthFlow
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly AuthorizeUrlBuilder $authorizeUrlBuilder,
    ) {}

    public function getAuthorizationUrl(Account $account, array $scopes, string $state): string
    {
        return $this->authorizeUrlBuilder->build(new AuthorizeUrlParameters(
            clientId: (string) $this->config->get('services.exact.client_id'),
            redirectUri: (string) $this->config->get('services.exact.redirect_uri'),
            state: $state,
            scope: $scopes === [] ? null : implode(' ', $scopes),
        ));
    }

    public function exchangeCode(Connection $connection, string $code): Connection
    {
        $token = $this->exactFor($connection)->exchangeAuthorizationCode($code);

        // Eerst persisteren: /Me gaat via de SDK-connector, die z'n token uit de
        // ConnectionTokenStore leest — en die leest de opgeslagen rij.
        $connection->fill([
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at' => $token->expiresAt,
            'status' => 'active',
            'revoked_at' => null,
            'oauth_state' => null,
            'oauth_state_expires_at' => null,
        ])->save();

        $me = $this->fetchMe($connection);

        $metadata = $connection->metadata ?? [];
        if ($me['user_id'] !== null) {
            $metadata['exact_user_id'] = ExactUserId::normalize($me['user_id']);
        }

        $connection->fill([
            'administratie_id' => $me['division'],
            'metadata' => $metadata,
        ])->save();

        RegisterExactWebhookSubscriptionsJob::dispatch($connection);

        SyncExactReferenceJob::dispatch($connection);

        return $connection;
    }

    public function refreshToken(Connection $connection): Connection
    {
        $this->exactFor($connection)->authenticator()->forceRefresh();

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

        $userId = $this->fetchMe($connection)['user_id'];

        if ($userId === null) {
            return false;
        }

        $metadata = $connection->metadata ?? [];
        $metadata['exact_user_id'] = ExactUserId::normalize($userId);

        $connection->update(['metadata' => $metadata]);

        return true;
    }

    private function exactFor(Connection $connection): Exact
    {
        app()->instance(ExactCredentialResolver::class, new HubExactCredentialResolver($connection));
        app()->instance(TokenStore::class, new ConnectionTokenStore($connection));
        app()->forgetInstance(Exact::class);

        /** @var Exact $exact */
        $exact = app(Exact::class);

        return $exact;
    }

    /** @return array{division: ?string, user_id: ?string} */
    private function fetchMe(Connection $connection): array
    {
        try {
            $response = $this->exactFor($connection)->connector('current')->send(new GetMe);
        } catch (Throwable) {
            return ['division' => null, 'user_id' => null];
        }

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
}
