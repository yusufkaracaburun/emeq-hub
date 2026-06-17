<?php

namespace App\Http\Controllers\Api\V1\Snelstart;

use App\Enums\Provider;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Connection;
use App\Models\PassThroughCall;
use App\Sanctum\TokenAbilities;
use App\Support\Snelstart\HeaderForwarder;
use App\Support\Snelstart\UpstreamErrorMapper;
use Dedoc\Scramble\Attributes\Group;
use Emeq\SnelstartApi\Http\Request\RawSnelstartRequest;
use Emeq\SnelstartApi\Snelstart;
use Illuminate\Http\Request;
use Saloon\Enums\Method;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

#[Group(name: 'Snelstart', description: 'Snelstart OData-calls met de clientKey + subscriptionKey van de gekoppelde Account.', weight: 60)]
class PassThroughController extends Controller
{
    private const ALLOWED_METHODS = ['GET', 'POST', 'PATCH', 'DELETE'];

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
            ? [TokenAbilities::SNELSTART_READ, TokenAbilities::SNELSTART_WRITE, TokenAbilities::ADMIN]
            : [TokenAbilities::SNELSTART_WRITE, TokenAbilities::ADMIN];

        $token = $request->user()?->currentAccessToken();
        $hasAbility = $token !== null && collect($required)->contains(fn (string $ability) => $token->can($ability));

        if (! $hasAbility) {
            return response()->json([
                'error' => 'insufficient_ability',
                'message' => 'Token mist vereiste ability voor deze methode.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (in_array($method, ['POST', 'PATCH'], true)) {
            $contentType = strtolower((string) $request->header('Content-Type', ''));
            if (! str_starts_with($contentType, 'application/json')) {
                return response()->json([
                    'error' => 'unsupported_content_type',
                    'message' => 'Pass-through accepteert alleen application/json voor POST/PATCH.',
                ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
            }
            $body = $request->json()->all();
        } else {
            $body = null;
        }

        $endpoint = '/'.ltrim($path, '/');
        $query = $request->query();
        $headers = HeaderForwarder::forward($request);

        $start = microtime(true);
        $upstreamError = null;
        $responseBody = '';
        $status = 0;
        $contentType = 'application/json';
        $extraHeaders = [];

        try {
            /** @var Snelstart $snelstart */
            $snelstart = app(Snelstart::class);

            $sdkRequest = new RawSnelstartRequest(
                method: Method::from($method),
                endpoint: $endpoint,
                query: $query,
                body: $body,
                headers: $headers,
            );

            $sdkResponse = $snelstart->connector()->send($sdkRequest);

            // De SDK throwt niet automatisch op failed-status — geef de
            // Snelstart-mapped exception (Authentication/Validation/Server/
            // NotFound/RateLimit) een kans om door UpstreamErrorMapper te
            // worden gemapt naar de juiste Hub-response.
            if ($sdkResponse->failed()) {
                $sdkResponse->throw();
            }

            $status = $sdkResponse->status();
            $responseBody = $sdkResponse->body();
            $contentType = $sdkResponse->header('Content-Type') ?? 'application/json';
        } catch (Throwable $e) {
            $mapped = UpstreamErrorMapper::mapException($e);
            $status = $mapped['status'];
            $responseBody = json_encode($mapped['body'], JSON_THROW_ON_ERROR);
            $contentType = 'application/json';
            $extraHeaders = $mapped['headers'];
            $upstreamError = $mapped['short_code'];
        }

        /** @var Account $account */
        $account = $request->attributes->get('snelstart_account');
        /** @var Connection $connection */
        $connection = $request->attributes->get('snelstart_connection');

        PassThroughCall::create([
            'consumer_id' => $request->user()->getKey(),
            'account_id' => $account->getKey(),
            'connection_id' => $connection->getKey(),
            'provider' => Provider::Snelstart->value,
            'method' => $method,
            'path' => $endpoint,
            'query_keys' => $query !== [] ? implode(',', array_keys($query)) : null,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'request_fingerprint' => (is_array($body) && $body !== [])
                ? substr(hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)), 0, 12)
                : null,
            'response_size_bytes' => strlen($responseBody),
            'upstream_error' => $upstreamError,
            'response_body' => PassThroughCall::errorBody($status, $responseBody),
            'created_at' => now(),
        ]);

        return response($responseBody, $status)->withHeaders(array_merge(
            ['Content-Type' => $contentType],
            $extraHeaders,
        ));
    }
}
