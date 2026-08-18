<?php

declare(strict_types=1);

namespace App\Integrations\Exact\PassThrough;

use App\Enums\Provider;
use App\Integrations\PassThrough\PassThroughContext;
use App\Integrations\PassThrough\PassThroughPipeline;
use App\Integrations\PassThrough\PassThroughRecorder;
use App\Integrations\PassThrough\UpstreamResult;
use App\Models\Account;
use App\Models\Connection;
use Emeq\ExactApi\Exact;
use Illuminate\Http\Request;
use Saloon\Contracts\Body\HasBody;
use Saloon\Http\Request as SdkRequest;
use Symfony\Component\HttpFoundation\Response;

final class ExactForwarder
{
    public function __construct(
        private readonly ExactErrorBudget $errorBudget,
        private readonly PassThroughPipeline $pipeline,
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

        return $this->pipeline->run(
            new PassThroughContext(
                provider: Provider::Exact,
                consumerId: $request->user()->getKey(),
                accountId: $account->getKey(),
                connectionId: $connection->getKey(),
                method: $method,
                path: $endpoint,
                query: $query,
                body: $body,
            ),
            function () use ($division, $sdkRequest): UpstreamResult {
                /** @var Exact $exact */
                $exact = app(Exact::class);

                $sdkResponse = $exact->connector($division)->send($sdkRequest);

                if ($sdkResponse->failed()) {
                    $sdkResponse->throw();
                }

                return new UpstreamResult(
                    status: $sdkResponse->status(),
                    body: $sdkResponse->body(),
                    contentType: $sdkResponse->header('Content-Type') ?? 'application/json',
                    headers: HeaderForwarder::forwardResponse($sdkResponse),
                );
            },
            fn (int $upstreamStatus) => $this->errorBudget->record($connection, $endpoint, $upstreamStatus),
        );
    }

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
