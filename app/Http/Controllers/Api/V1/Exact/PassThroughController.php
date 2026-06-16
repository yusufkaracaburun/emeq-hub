<?php

namespace App\Http\Controllers\Api\V1\Exact;

use App\Enums\Provider;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Connection;
use App\Models\PassThroughCall;
use App\Sanctum\TokenAbilities;
use App\Support\Exact\HeaderForwarder;
use App\Support\Exact\UpstreamErrorMapper;
use Dedoc\Scramble\Attributes\Group;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\Http\Request\RawExactRequest;
use Illuminate\Http\Request;
use Saloon\Enums\Method;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Exact Online pass-through. Forward een consumer-request naar de Exact REST-API
 * met de tokens van de gekoppelde Account (division uit `administratie_id`).
 * Gemodelleerd op de Snelstart-pass-through. De SDK-OAuthAuthenticator refresht
 * reactief mét rotatie via de Connection-backed TokenStore.
 */
#[Group(name: 'Exact Online', description: 'Exact Online REST-calls met de OAuth-tokens van de gekoppelde Account; division in het pad.', weight: 60)]
class PassThroughController extends Controller
{
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

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

        $division = (string) $connection->administratie_id;

        if ($division === '') {
            return response()->json([
                'error' => 'connection_incomplete',
                'message' => 'Exact-Connection heeft geen division (administratie_id) — herkoppel de Account.',
            ], Response::HTTP_CONFLICT);
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
            /** @var Exact $exact */
            $exact = app(Exact::class);

            $sdkResponse = $exact->connector($division)->send(new RawExactRequest(
                method: Method::from($method),
                endpoint: $endpoint,
                query: $query,
                body: $body,
                headers: $headers,
            ));

            // De SDK throwt niet automatisch op failed-status — geef de Exact-mapped
            // exception een kans om door UpstreamErrorMapper te worden gemapt.
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

        PassThroughCall::create([
            'consumer_id' => $request->user()->getKey(),
            'account_id' => $account->getKey(),
            'connection_id' => $connection->getKey(),
            'provider' => Provider::Exact->value,
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
            'created_at' => now(),
        ]);

        return response($responseBody, $status)->withHeaders(array_merge(
            ['Content-Type' => $contentType],
            $extraHeaders,
        ));
    }
}
