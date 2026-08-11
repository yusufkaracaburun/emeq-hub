<?php

namespace App\Http\Controllers\Api\V1\Exact;

use App\Http\Controllers\Api\V1\Concerns\GuardsPassThroughRequest;
use App\Http\Controllers\Controller;
use App\Integrations\Exact\PassThrough\ExactForwarder;
use App\Integrations\Exact\PassThrough\ExactPathWhitelist;
use App\Integrations\Exact\PassThrough\HeaderForwarder;
use App\Models\Account;
use App\Models\Connection;
use App\Sanctum\TokenAbilities;
use Dedoc\Scramble\Attributes\Group;
use Emeq\ExactApi\Http\Request\RawExactRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Saloon\Enums\Method;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exact Online pass-through. Forward een consumer-request naar de Exact REST-API
 * met de tokens van de gekoppelde Account (division uit `administratie_id`).
 * Gemodelleerd op de Snelstart-pass-through. De SDK-OAuthAuthenticator refresht
 * reactief mét rotatie via de Connection-backed TokenStore.
 *
 * Forward + audit-logging leeft in ExactForwarder, gedeeld met de named
 * resource-endpoints (bv. GL Accounts) zodat elke Exact-call op één plek wordt
 * vastgelegd.
 */
#[Group(name: 'Exact', description: 'Generieke Exact Online REST-pass-through (`/exact/{path}`) met de OAuth-tokens van de gekoppelde Account; division in het pad. Named resource-endpoints staan onder hun eigen `Exact · …`-groep.', weight: 60)]
class PassThroughController extends Controller
{
    use GuardsPassThroughRequest;

    // Geen DELETE: de Hub verwijdert geen data bij Exact via de pass-through
    // (least-privilege / D&S vraag 2). Test-opruiming loopt via de connector
    // rechtstreeks (PurgeTestData), niet via deze route.
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH'];

    private const BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    public function __construct(
        private readonly ExactForwarder $forwarder,
        private readonly ExactPathWhitelist $whitelist,
    ) {}

    public function __invoke(Request $request, string $path): Response
    {
        $method = strtoupper($request->method());

        if ($response = $this->guardMethodAllowed($method, self::ALLOWED_METHODS)) {
            return $response;
        }

        $required = $method === 'GET'
            ? [TokenAbilities::EXACT_READ, TokenAbilities::EXACT_WRITE, TokenAbilities::ADMIN]
            : [TokenAbilities::EXACT_WRITE, TokenAbilities::ADMIN];

        if ($response = $this->guardTokenAbility($request, $required)) {
            return $response;
        }

        if ($response = $this->guardJsonContentType($request, $method, self::BODY_METHODS)) {
            return $response;
        }

        $body = in_array($method, self::BODY_METHODS, true) ? $request->json()->all() : null;

        /** @var Account $account */
        $account = $request->attributes->get('exact_account');
        /** @var Connection $connection */
        $connection = $request->attributes->get('exact_connection');

        if (! $this->whitelist->allows($path)) {
            Log::warning('exact.passthrough.path_blocked', [
                'consumer_id' => $account->consumer_id ?? null,
                'method' => $method,
                'path' => $path,
            ]);

            return response()->json([
                'error' => 'path_not_allowed',
                'message' => 'Dit Exact-pad valt buiten de toegestane resources van de Hub.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $this->forwarder->forward($request, $account, $connection, new RawExactRequest(
            method: Method::from($method),
            endpoint: '/'.ltrim($path, '/'),
            query: $request->query(),
            body: $body,
            headers: HeaderForwarder::forward($request),
        ));
    }
}
