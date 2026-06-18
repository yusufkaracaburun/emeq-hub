<?php

declare(strict_types=1);

namespace App\Support\Exact;

use App\Enums\Provider;
use App\Models\Account;
use App\Models\Connection;
use App\Models\PassThroughCall;
use Emeq\ExactApi\Exact;
use Illuminate\Http\Request;
use Saloon\Contracts\Body\HasBody;
use Saloon\Http\Request as SdkRequest;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Stuurt één division-scoped Exact-SDK-request door en logt 'm in pass_through_calls.
 *
 * Eén audit-/trace-pad voor zowel de generieke pass-through (RawExactRequest) als de
 * named resource-endpoints (GetGlAccounts/…): method + geraakte Exact-endpoint komen
 * uit het SDK-request zelf (`resolveEndpoint()`), zodat de Hub het pad niet dupliceert.
 * De endpoint-/payload-kennis leeft in de SDK; de Hub regelt division-scope + audit.
 */
final class ExactForwarder
{
    public function __construct(private readonly ExactErrorBudget $errorBudget) {}

    public function forward(
        Request $request,
        Account $account,
        Connection $connection,
        SdkRequest $sdkRequest,
    ): Response {
        $division = (string) $connection->administratie_id;

        if ($division === '') {
            return response()->json([
                'error' => 'connection_incomplete',
                'message' => 'Exact-Connection heeft geen division (administratie_id) — herkoppel de Account.',
            ], Response::HTTP_CONFLICT);
        }

        $method = $sdkRequest->getMethod()->value;
        $endpoint = '/'.ltrim($sdkRequest->resolveEndpoint(), '/');

        if ($this->errorBudget->isOpen($connection, $endpoint)) {
            return $this->blocked($request, $account, $connection, $method, $endpoint, $sdkRequest);
        }
        $query = $sdkRequest->query()->all();
        $body = $sdkRequest instanceof HasBody ? $sdkRequest->body()->all() : null;

        $start = microtime(true);
        $upstreamError = null;
        $responseBody = '';
        $status = 0;
        $upstreamStatus = 0;
        $contentType = 'application/json';
        $extraHeaders = [];

        try {
            /** @var Exact $exact */
            $exact = app(Exact::class);

            $sdkResponse = $exact->connector($division)->send($sdkRequest);

            // De SDK throwt niet automatisch op failed-status — geef de Exact-mapped
            // exception een kans om door UpstreamErrorMapper te worden gemapt.
            if ($sdkResponse->failed()) {
                $sdkResponse->throw();
            }

            $status = $sdkResponse->status();
            $upstreamStatus = $status;
            $responseBody = $sdkResponse->body();
            $contentType = $sdkResponse->header('Content-Type') ?? 'application/json';
            $extraHeaders = HeaderForwarder::forwardResponse($sdkResponse);
        } catch (Throwable $e) {
            $mapped = UpstreamErrorMapper::mapException($e);
            $status = $mapped['status'];
            // Tel tegen het error-budget op wat Exact zélf teruggaf (de mapper maskeert
            // 401/403 naar 502 voor de consumer; de breaker spiegelt Exact's limiet).
            $upstreamStatus = (int) ($mapped['body']['upstream_status'] ?? $mapped['status']);
            $responseBody = json_encode($mapped['body'], JSON_THROW_ON_ERROR);
            $contentType = 'application/json';
            $extraHeaders = $mapped['headers'];
            $upstreamError = $mapped['short_code'];
        }

        $this->errorBudget->record($connection, $endpoint, $upstreamStatus);

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
            'response_body' => PassThroughCall::errorBody($status, $responseBody),
            'created_at' => now(),
        ]);

        return response($responseBody, $status)->withHeaders(array_merge(
            ['Content-Type' => $contentType],
            $extraHeaders,
        ));
    }

    /**
     * Breaker open: blokkeer Hub-side met 429 i.p.v. door te tikken naar Exact.
     * Wordt als pass_through_call gelogd (status 429, upstream_error=circuit_open)
     * zodat de blokkade zichtbaar is in de audit-/admin-laag.
     */
    private function blocked(
        Request $request,
        Account $account,
        Connection $connection,
        string $method,
        string $endpoint,
        SdkRequest $sdkRequest,
    ): Response {
        $query = $sdkRequest->query()->all();
        $retryAfter = $this->errorBudget->retryAfter();

        $body = json_encode([
            'error' => 'rate_limited',
            'message' => 'Te veel fouten op dit Exact-endpoint; tijdelijk geblokkeerd om de gedeelde Exact-app-key te beschermen.',
        ], JSON_THROW_ON_ERROR);

        PassThroughCall::create([
            'consumer_id' => $request->user()->getKey(),
            'account_id' => $account->getKey(),
            'connection_id' => $connection->getKey(),
            'provider' => Provider::Exact->value,
            'method' => $method,
            'path' => $endpoint,
            'query_keys' => $query !== [] ? implode(',', array_keys($query)) : null,
            'status' => Response::HTTP_TOO_MANY_REQUESTS,
            'duration_ms' => 0,
            'request_fingerprint' => null,
            'response_size_bytes' => strlen($body),
            'upstream_error' => 'circuit_open',
            'response_body' => $body,
            'created_at' => now(),
        ]);

        return response($body, Response::HTTP_TOO_MANY_REQUESTS)->withHeaders([
            'Content-Type' => 'application/json',
            'Retry-After' => (string) $retryAfter,
        ]);
    }
}
