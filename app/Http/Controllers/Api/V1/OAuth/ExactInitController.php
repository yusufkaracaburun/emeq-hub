<?php

namespace App\Http\Controllers\Api\V1\OAuth;

use App\Enums\Provider;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Consumer;
use App\OAuth\OAuthFlowRegistry;
use App\Support\OAuth\ReturnUrlResolver;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Exact Online OAuth-init. Parallel aan InitController (Mollie) — dedup naar een
 * generieke {provider}-controller is een uitgestelde follow-up (issue #3 / audit A2).
 */
#[Group(name: 'OAuth Connect', description: 'OAuth-broker — init de authorize-flow en handle de callback van de partner.', weight: 40)]
class ExactInitController extends Controller
{
    public function __construct(
        private readonly OAuthFlowRegistry $registry,
        private readonly ReturnUrlResolver $returnUrls,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __invoke(Request $request): array
    {
        $validated = $request->validate([
            'account_external_id' => ['required', 'string'],
            'return_url' => ['nullable', 'url'],
        ]);

        /** @var Consumer $consumer */
        $consumer = $request->user();

        $account = $consumer->accounts()
            ->where('external_id', $validated['account_external_id'])
            ->firstOrFail();

        $state = Str::random(48);

        $connection = Connection::startOAuthFlow(
            $account,
            Provider::Exact,
            $state,
            $this->returnUrls->resolve($consumer, $validated['return_url'] ?? null),
        );

        // Exact gebruikt géén scopes.
        $redirectUrl = $this->registry->for(Provider::Exact->value)->getAuthorizationUrl($account, [], $state);

        return [
            'connection_id' => (string) $connection->id,
            'redirect_url' => $redirectUrl,
        ];
    }
}
