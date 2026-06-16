<?php

namespace App\Http\Controllers\Api\V1\OAuth;

use App\Enums\Provider;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\OAuth\OAuthFlowRegistry;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Exact Online OAuth-callback. Parallel aan CallbackController (Mollie) — dedup
 * naar een generieke {provider}-controller is een uitgestelde follow-up
 * (issue #3 / audit A2). Publiek: de state-parameter is de auth (D-07).
 */
#[Group(name: 'OAuth Connect', description: 'OAuth-broker — init de authorize-flow en handle de callback van de partner.', weight: 40)]
class ExactCallbackController extends Controller
{
    public function __construct(private readonly OAuthFlowRegistry $registry) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        $connection = Connection::query()
            ->where('provider', Provider::Exact->value)
            ->where('status', 'pending')
            ->where('oauth_state', $validated['state'])
            ->where('oauth_state_expires_at', '>', now())
            ->first();

        if ($connection === null) {
            return response()->json(
                ['error' => 'invalid_or_expired_state'],
                400,
            );
        }

        $this->registry->for(Provider::Exact->value)->exchangeCode($connection, $validated['code']);

        return response()->json([
            'connection_id' => (string) $connection->id,
            'status' => 'active',
        ]);
    }
}
