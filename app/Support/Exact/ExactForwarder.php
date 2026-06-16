<?php

declare(strict_types=1);

namespace App\Support\Exact;

use App\Enums\Provider;
use App\Models\Account;
use App\Models\Connection;
use App\Models\PassThroughCall;
use Emeq\ExactApi\Exact;
use Emeq\ExactApi\Http\Request\RawExactRequest;
use Illuminate\Http\Request;
use Saloon\Enums\Method;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Stuurt één division-scoped Exact REST-call door en logt 'm in pass_through_calls.
 *
 * Eén audit-/trace-pad voor zowel de generieke pass-through als de named
 * resource-endpoints: elke call legt provider + method + de geraakte Exact-
 * endpoint (`path`) vast, zodat per request herleidbaar is welke Exact-endpoint
 * achter een Hub-endpoint zit. Validatie (method/ability/content-type) en de
 * endpoint-keuze blijven bij de aanroepende controller.
 */
final class ExactForwarder
{
    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>|null  $body
     */
    public function forward(
        Request $request,
        Account $account,
        Connection $connection,
        string $method,
        string $endpoint,
        array $query = [],
        ?array $body = null,
    ): Response {
        $division = (string) $connection->administratie_id;

        if ($division === '') {
            return response()->json([
                'error' => 'connection_incomplete',
                'message' => 'Exact-Connection heeft geen division (administratie_id) — herkoppel de Account.',
            ], Response::HTTP_CONFLICT);
        }

        $endpoint = '/'.ltrim($endpoint, '/');
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
