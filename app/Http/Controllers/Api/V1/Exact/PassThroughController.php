<?php

namespace App\Http\Controllers\Api\V1\Exact;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Connection;
use App\Sanctum\TokenAbilities;
use App\Support\Exact\ExactForwarder;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
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
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly ExactForwarder $forwarder) {}

    public function __invoke(Request $request, string $path): Response
    {
        $method = strtoupper($request->method());

        if (! in_array($method, self::ALLOWED_METHODS, true)) {
            return response()->json([
                'error' => 'method_not_allowed',
                'message' => 'HTTP method niet toegestaan op pass-through-route.',
            ], Response::HTTP_METHOD_NOT_ALLOWED);
        }

        $required = $method === 'GET'
            ? [TokenAbilities::EXACT_READ, TokenAbilities::EXACT_WRITE, TokenAbilities::ADMIN]
            : [TokenAbilities::EXACT_WRITE, TokenAbilities::ADMIN];

        $token = $request->user()?->currentAccessToken();
        $hasAbility = $token !== null && collect($required)->contains(fn (string $ability) => $token->can($ability));

        if (! $hasAbility) {
            return response()->json([
                'error' => 'insufficient_ability',
                'message' => 'Token mist vereiste ability voor deze methode.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $contentType = strtolower((string) $request->header('Content-Type', ''));
            if (! str_starts_with($contentType, 'application/json')) {
                return response()->json([
                    'error' => 'unsupported_content_type',
                    'message' => 'Pass-through accepteert alleen application/json voor POST/PUT/PATCH.',
                ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
            }
            $body = $request->json()->all();
        } else {
            $body = null;
        }

        /** @var Account $account */
        $account = $request->attributes->get('exact_account');
        /** @var Connection $connection */
        $connection = $request->attributes->get('exact_connection');

        return $this->forwarder->forward(
            $request,
            $account,
            $connection,
            $method,
            $path,
            $request->query(),
            $body,
        );
    }
}
