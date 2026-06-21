<?php

namespace App\Http\Controllers\Api\V1\OAuth;

use App\Enums\Provider;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Consumer;
use App\OAuth\Exceptions\ProviderDisabledException;
use App\OAuth\OAuthFlowRegistry;
use App\Support\OAuth\ReturnUrlResolver;
use App\Support\ProviderCredentialDescriptor;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Provider-agnostische OAuth-init voor élke huidige en toekomstige OAuth-provider.
 * Resolved de provider uit de route, de scopes uit config en de flow uit de
 * OAuthFlowRegistry — een nieuwe provider is koppelbaar zonder nieuwe route of
 * controller. De named routes `/oauth/{mollie,exact}/init` wijzen hierheen via
 * `->defaults('provider', …)` (behouden per-provider ability + backward-compat);
 * `/oauth/{provider}/init` vangt elke toekomstige provider.
 */
#[Group(name: 'OAuth Connect', description: 'OAuth-broker — init de authorize-flow en handle de callback van de partner.', weight: 40)]
class ProviderInitController extends Controller
{
    public function __construct(
        private readonly OAuthFlowRegistry $registry,
        private readonly ReturnUrlResolver $returnUrls,
    ) {}

    /**
     * @return array{connection_id: string, redirect_url: string}
     */
    public function __invoke(Request $request, string $provider): array
    {
        $providerEnum = Provider::tryFrom($provider);

        abort_if($providerEnum === null, 404, 'unknown_provider');

        // Geen OAuth-flow (bv. Snelstart = clientkey) → niet via deze route te koppelen.
        $descriptor = ProviderCredentialDescriptor::tryFor($provider);
        abort_if($descriptor?->oauthFlowKey === null, 404, 'provider_not_connectable');

        $validated = $request->validate([
            'account_external_id' => ['required', 'string'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'return_url' => ['nullable', 'url'],
        ]);

        /** @var Consumer $consumer */
        $consumer = $request->user();

        // Eén-knop-onboarding: de consumer-app start de koppeling zonder het
        // Account eerst apart aan te maken. external_id is per-Consumer genamespaced
        // (firstOrCreate is scoped op $consumer->accounts()), dus geen cross-tenant-leak.
        $account = $consumer->accounts()->firstOrCreate(
            ['external_id' => $validated['account_external_id']],
            ['display_name' => $validated['display_name'] ?? null],
        );

        try {
            $flow = $this->registry->for($providerEnum->value);
        } catch (ProviderDisabledException) {
            abort(503, 'provider_disabled');
        } catch (InvalidArgumentException) {
            abort(404, 'unknown_provider');
        }

        $state = Str::random(48);

        $connection = Connection::startOAuthFlow(
            $account,
            $providerEnum,
            $state,
            $this->returnUrls->resolve($consumer, $validated['return_url'] ?? null, $request->headers->get('Origin')),
        );

        $scopes = (array) config("services.{$provider}.connect.scopes", []);

        return [
            'connection_id' => (string) $connection->id,
            'redirect_url' => $flow->getAuthorizationUrl($account, $scopes, $state),
        ];
    }
}
