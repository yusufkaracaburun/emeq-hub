<?php

namespace App\Http\Controllers\Api\V1\OAuth;

use App\Enums\Provider;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\OAuth\OAuthFlowRegistry;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Exact Online OAuth-callback. Parallel aan CallbackController (Mollie) — dedup
 * naar een generieke {provider}-controller is een uitgestelde follow-up
 * (issue #3 / audit A2). Publiek: de state-parameter is de auth (D-07).
 *
 * Browser-endpoint: na afhandeling redirecten we (PRG) naar een getekende
 * landingsroute (`oauth.connected` / `oauth.failed`) — zie OAuthLandingController.
 */
#[Group(name: 'OAuth Connect', description: 'OAuth-broker — init de authorize-flow en handle de callback van de partner.', weight: 40)]
class ExactCallbackController extends Controller
{
    public function __construct(private readonly OAuthFlowRegistry $registry) {}

    public function __invoke(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->failed((string) $request->query('error'));
        }

        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        if ($code === '' || $state === '') {
            return $this->failed('missing_parameters');
        }

        $connection = Connection::query()
            ->where('provider', Provider::Exact->value)
            ->where('status', 'pending')
            ->where('oauth_state', $state)
            ->where('oauth_state_expires_at', '>', now())
            ->first();

        if ($connection === null) {
            return $this->failed('invalid_or_expired_state');
        }

        try {
            $this->registry->for(Provider::Exact->value)->exchangeCode($connection, $code);
        } catch (Throwable $e) {
            report($e);

            return $this->failed('exchange_failed', $connection->oauth_return_url);
        }

        return redirect()->to(URL::temporarySignedRoute(
            'oauth.connected',
            now()->addMinutes(10),
            ['connection' => $connection->id],
        ));
    }

    private function failed(string $reason, ?string $returnUrl = null): RedirectResponse
    {
        $params = ['provider' => Provider::Exact->value, 'reason' => $reason];

        if ($returnUrl !== null) {
            $params['return_url'] = $returnUrl;
        }

        return redirect()->to(URL::temporarySignedRoute(
            'oauth.failed',
            now()->addMinutes(10),
            $params,
        ));
    }
}
