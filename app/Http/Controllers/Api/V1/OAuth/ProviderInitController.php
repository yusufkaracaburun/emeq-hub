<?php

namespace App\Http\Controllers\Api\V1\OAuth;

use App\Actions\Connect\ProviderNotConnectableException;
use App\Actions\Connect\StartProviderConnection;
use App\Enums\Provider;
use App\Http\Controllers\Controller;
use App\Integrations\Exceptions\ProviderDisabledException;
use App\Integrations\OAuth\ReturnUrlResolver;
use App\Models\Consumer;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;

#[Group(name: 'OAuth Connect', description: 'OAuth-broker — init de authorize-flow en handle de callback van de partner.', weight: 40)]
class ProviderInitController extends Controller
{
    public function __construct(
        private readonly StartProviderConnection $startConnection,
        private readonly ReturnUrlResolver $returnUrls,
    ) {}

    /** @return array{connection_id: string, redirect_url: string} */
    public function __invoke(Request $request, string $provider): array
    {
        $providerEnum = Provider::tryFrom($provider);

        abort_if($providerEnum === null, 404, 'unknown_provider');

        $validated = $request->validate([
            'account_external_id' => ['required', 'string'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'return_url' => ['nullable', 'url'],
        ]);

        /** @var Consumer $consumer */
        $consumer = $request->user();

        $account = $consumer->accounts()->firstOrCreate(
            ['external_id' => $validated['account_external_id']],
            ['display_name' => $validated['display_name'] ?? null],
        );

        $returnUrl = $this->returnUrls->resolve(
            $consumer,
            $validated['return_url'] ?? null,
            $request->headers->get('Origin'),
        );

        try {
            $result = $this->startConnection->handle($account, $providerEnum, $returnUrl);
        } catch (ProviderNotConnectableException) {
            abort(404, 'provider_not_connectable');
        } catch (ProviderDisabledException) {
            abort(503, 'provider_disabled');
        }

        return [
            'connection_id' => (string) $result['connection']->public_id,
            'redirect_url' => $result['redirect_url'],
        ];
    }
}
