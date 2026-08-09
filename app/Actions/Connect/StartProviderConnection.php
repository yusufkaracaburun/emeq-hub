<?php

declare(strict_types=1);

namespace App\Actions\Connect;

use App\Enums\Provider;
use App\Models\Account;
use App\Models\Connection;
use App\OAuth\Exceptions\ProviderDisabledException;
use App\OAuth\OAuthFlowRegistry;
use App\Support\ProviderCredentialDescriptor;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Zet één OAuth-authorize-flow op voor (account, provider): state genereren,
 * de Connection-rij reuse-or-createn en de authorize-URL opvragen.
 *
 * Gedeeld door de consumer-API (`POST /v1/oauth/{provider}/init`, server-to-
 * server) en de handoff-pagina waar de eindgebruiker zelf op "Koppelen" klikt.
 * Beide paden moeten dezelfde state-, reuse- en scope-semantiek hebben; die
 * hoort daarom hier en niet in een controller.
 */
class StartProviderConnection
{
    public function __construct(private readonly OAuthFlowRegistry $registry) {}

    /**
     * @return array{connection: Connection, redirect_url: string}
     *
     * @throws ProviderNotConnectableException Provider bestaat niet of heeft geen OAuth-flow.
     * @throws ProviderDisabledException       Kill-switch staat uit voor deze provider.
     */
    public function handle(Account $account, Provider $provider, ?string $returnUrl): array
    {
        $descriptor = ProviderCredentialDescriptor::tryFor($provider->value);

        if ($descriptor?->oauthFlowKey === null) {
            throw new ProviderNotConnectableException($provider->value);
        }

        try {
            $flow = $this->registry->for($provider->value);
        } catch (InvalidArgumentException) {
            throw new ProviderNotConnectableException($provider->value);
        }

        $state = Str::random(48);

        $connection = Connection::startOAuthFlow($account, $provider, $state, $returnUrl);

        /** @var list<string> $scopes */
        $scopes = (array) config("services.{$provider->value}.connect.scopes", []);

        return [
            'connection' => $connection,
            'redirect_url' => $flow->getAuthorizationUrl($account, $scopes, $state),
        ];
    }
}
