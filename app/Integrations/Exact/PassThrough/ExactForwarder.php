<?php

declare(strict_types=1);

namespace App\Integrations\Exact\PassThrough;

use App\Enums\Provider;
use App\Integrations\Exact\Errors\UpstreamErrorMapper;
use App\Integrations\PassThrough\PassThroughRecorder;
use App\Models\Account;
use App\Models\Connection;
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
    public function __construct(
        private readonly ExactErrorBudget $errorBudget,
        private readonly PassThroughRecorder $recorder,
    ) {}

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

        $this->recorder->record(
            provider: Provider::Exact,
            consumerId: $request->user()->getKey(),
            accountId: $account->getKey(),
            connectionId: $connection->getKey(),
            method: $method,
            path: $endpoint,
            status: $status,
            responseBody: $responseBody,
            startedAt: $start,
            query: $query,
            body: $body,
            upstreamError: $upstreamError,
        );

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

        $this->recorder->record(
            provider: Provider::Exact,
            consumerId: $request->user()->getKey(),
            accountId: $account->getKey(),
            connectionId: $connection->getKey(),
            method: $method,
            path: $endpoint,
            status: Response::HTTP_TOO_MANY_REQUESTS,
            responseBody: $body,
            query: $query,
            upstreamError: 'circuit_open',
        );

        return response($body, Response::HTTP_TOO_MANY_REQUESTS)->withHeaders([
            'Content-Type' => 'application/json',
            'Retry-After' => (string) $retryAfter,
        ]);
    }
}
