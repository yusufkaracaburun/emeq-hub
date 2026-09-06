<?php

declare(strict_types=1);

namespace App\Integrations\Itheorie\Http\Api\Concerns;

use App\Enums\Provider;
use App\Integrations\Itheorie\PurchaseLedger;
use App\Integrations\PassThrough\PassThroughContext;
use App\Integrations\PassThrough\PassThroughPipeline;
use App\Integrations\PassThrough\UpstreamResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

trait ForwardsToItheorie
{
    public function __construct(
        private readonly PassThroughPipeline $pipeline,
        private readonly PurchaseLedger $ledger,
    ) {}

    private function consumerId(Request $request): int
    {
        return (int) $request->user()->getKey();
    }

    private function ownsAccessCode(Request $request, string $accessCode): bool
    {
        return $this->ledger->ownsAccessCode($this->consumerId($request), $accessCode);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'error' => 'not_found',
            'message' => 'Onbekend bij iTheorie, of niet van deze consumer.',
        ], Response::HTTP_NOT_FOUND);
    }

    /** @return array{page: int, limit: int} */
    private function pagination(Request $request): array
    {
        return [
            'page' => max(1, $request->integer('page', 1)),
            'limit' => min(100, max(1, $request->integer('limit', 50))),
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @param  callable(): array<string, mixed>  $call
     */
    private function forward(
        Request $request,
        string $method,
        string $path,
        array $query,
        callable $call,
        ?array $body = null,
    ): JsonResponse {
        try {
            $response = $this->pipeline->run(
                new PassThroughContext(
                    provider: Provider::Itheorie,
                    consumerId: $this->consumerId($request),
                    accountId: null,
                    connectionId: null,
                    method: $method,
                    path: $path,
                    query: $query,
                    body: $body,
                ),
                static function () use ($call): UpstreamResult {
                    return new UpstreamResult(
                        status: 200,
                        body: json_encode($call(), JSON_THROW_ON_ERROR),
                        contentType: 'application/json',
                    );
                },
            );
        } catch (JsonException) {
            return response()->json([
                'error' => 'serialization_error',
                'message' => 'Interne fout bij het serialiseren van het iTheorie-antwoord.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        /** @var string $content */
        $content = $response->getContent();

        return response()->json(json_decode($content, true), $response->getStatusCode());
    }
}
