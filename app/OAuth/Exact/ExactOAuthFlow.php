<?php

namespace App\OAuth\Exact;

use App\Jobs\Exact\DeleteExactWebhookSubscriptionsJob;
use App\Jobs\Accounting\SyncExactReferenceJob;
use App\Jobs\Exact\RegisterExactWebhookSubscriptionsJob;
use App\Models\Account;
use App\Models\Connection;
use App\OAuth\Contracts\OAuthFlow;
use Emeq\ExactApi\OData\Envelope;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;

/**
 * Exact Online OAuth2 (authorization_code, Seamless). Gemodelleerd op
 * MollieConnectOAuthFlow, met de empirisch-geverifieerde Exact-afwijkingen:
 *
 *  - Roterend single-use refresh-token: élke refresh geeft een NIEUW refresh_token;
 *    het oude vervalt direct. refreshToken() persisteert het geroteerde token onder
 *    een per-connection lock — mis je dat, dan is de koppeling na 10 min dood.
 *  - Refresh PAS ná expiry: Exact weigert een refresh zolang de access_token nog
 *    geldig is (HTTP 400 "Rate limit exceeded: access_token not expired"). Dus géén
 *    proactieve 5-min-marge zoals Mollie; alleen refreshen wanneer écht verlopen.
 *  - Division na exchangeCode ophalen → administratie_id.
 *
 * OAuth-shapes komen uit Exact's officiële Postman-collection (issue #3),
 * empirisch gevalideerd tegen de live test-app.
 */
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

        // Exact gebruikt géén scopes; alleen meesturen als de host ze toch opgeeft.
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

        $connection->fill([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expires_at' => now()->addSeconds((int) $token['expires_in']),
            'administratie_id' => $this->fetchDivision((string) $token['access_token']),
            'status' => 'active',
            'oauth_state' => null,
            'oauth_state_expires_at' => null,
        ])->save();

        // Division is nu bekend → registreer de webhook-subscriptions async (de
        // subscribe-handshake mag de OAuth-callback niet blokkeren). No-op als er
        // geen topics geconfigureerd zijn.
        RegisterExactWebhookSubscriptionsJob::dispatch($connection);

        // Spiegel meteen de boekhoud-referentiedata (grootboek/BTW/dagboeken) zodat de
        // accounting-sync direct lokaal kan resolven zonder live partner-call.
        SyncExactReferenceJob::dispatch($connection);

        return $connection;
    }

    public function refreshToken(Connection $connection): Connection
    {
        return Cache::lock("oauth:refresh:{$connection->id}", 30)->block(15, function () use ($connection) {
            $connection->refresh();

            // Exact weigert refresh zolang de access_token nog geldig is, dus we
            // refreshen alleen wanneer die écht verlopen is (geen proactieve marge).
            if ($connection->expires_at && $connection->expires_at->gt(now())) {
                return $connection;
            }

            $response = $this->http->asForm()->post($this->tokenUrl(), [
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
                'client_id' => $this->config->get('services.exact.client_id'),
                'client_secret' => $this->config->get('services.exact.client_secret'),
            ]);

            if ($response->failed()) {
                // Clock-skew: onze klok zei "verlopen", maar Exact vindt de token nog
                // geldig → huidige token is nog bruikbaar, niet falen.
                if ($response->status() === 400 && str_contains($response->body(), 'not expired')) {
                    return $connection;
                }

                $response->throw();
            }

            $body = $response->json();

            $connection->fill([
                'access_token' => $body['access_token'],
                // Roterend: nieuw refresh_token persisteren (fallback op oud als Exact
                // er geen teruggeeft — defensief, hoort niet voor te komen).
                'refresh_token' => $body['refresh_token'] ?? $connection->refresh_token,
                'expires_at' => now()->addSeconds((int) $body['expires_in']),
            ])->save();

            return $connection;
        });
    }

    public function revoke(Connection $connection): void
    {
        // Exact heeft geen gedocumenteerd token-revoke-endpoint; deprovisioning loopt
        // via de App-Center "Niet meer gebruiken"-flow (server-contract nog niet
        // vastgelegd). Lokaal markeren + de webhook-subscriptions opzeggen (best-effort,
        // de job faalt niet hard als de delete na revoke niet meer kan).
        DeleteExactWebhookSubscriptionsJob::dispatch($connection);

        $connection->update(['status' => 'revoked', 'revoked_at' => now()]);
    }

    private function fetchDivision(string $accessToken): ?string
    {
        $response = $this->http->withToken($accessToken)->acceptJson()
            ->get($this->apiBaseUrl().'/api/v1/current/Me');

        if ($response->failed()) {
            return null;
        }

        $division = Envelope::results($response->json())[0]['CurrentDivision'] ?? null;

        return $division !== null ? (string) $division : null;
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
