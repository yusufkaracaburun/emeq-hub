<?php

declare(strict_types=1);

namespace App\Integrations\PassThrough;

use App\Integrations\Errors\UpstreamErrorMapperRegistry;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Het frame om elke pass-through-call: klok starten, de partner aanroepen, een
 * exception naar het Hub-foutcontract vertalen, de auditrij schrijven en de
 * response renderen.
 *
 * Dat frame stond vier keer los in de codebase — Exact, Snelstart, Mollie en Mollie
 * Connect — met per kopie dezelfde volgorde en dezelfde randgevallen. Vier kopieën
 * betekent dat een fix aan de audit- of foutafhandeling er drie mist, en dat elke
 * nieuwe provider een vijfde bijschrijft.
 *
 * Wat per provider verschilt zit in de aanroeper: welk SDK-request eruit gaat, en
 * hoe het antwoord tot een {@see UpstreamResult} komt. De foutmapper wordt hier via
 * de registry gekozen, niet op importniveau — anders kiest elke aanroeper er weer
 * zelf een.
 */
final class PassThroughPipeline
{
    public function __construct(
        private readonly UpstreamErrorMapperRegistry $errorMappers,
        private readonly PassThroughRecorder $recorder,
    ) {}

    /**
     * @param  callable(): UpstreamResult  $invoke  de partner-call
     * @param  (callable(int): void)|null  $observeUpstreamStatus  krijgt de status die de
     *                                                             partner zélf gaf, ook wanneer
     *                                                             de mapper 'm voor de consumer
     *                                                             maskeert (Exact's error-budget
     *                                                             spiegelt Exact's eigen limiet)
     *
     * @throws \JsonException
     */
    public function run(
        PassThroughContext $context,
        callable $invoke,
        ?callable $observeUpstreamStatus = null,
    ): Response {
        $start = microtime(true);
        $upstreamError = null;

        try {
            $result = $invoke();
            $upstreamStatus = $result->status;
        } catch (Throwable $e) {
            $mapped = $this->errorMappers->map($context->provider->value, $e);

            $result = new UpstreamResult(
                status: $mapped['status'],
                body: json_encode($mapped['body'], JSON_THROW_ON_ERROR),
                headers: $mapped['headers'],
            );
            $upstreamStatus = (int) ($mapped['body']['upstream_status'] ?? $mapped['status']);
            $upstreamError = $mapped['short_code'];
        }

        if ($observeUpstreamStatus !== null) {
            $observeUpstreamStatus($upstreamStatus);
        }

        $this->recorder->record(
            provider: $context->provider,
            consumerId: $context->consumerId,
            accountId: $context->accountId,
            connectionId: $context->connectionId,
            method: $context->method,
            path: $context->path,
            status: $result->status,
            responseBody: $result->body,
            startedAt: $start,
            query: $context->query,
            body: $context->body,
            upstreamError: $upstreamError,
            direction: $context->direction,
            extra: $context->extra,
        );

        return response($result->body, $result->status)->withHeaders([
            'Content-Type' => $result->contentType,
            ...$result->headers,
        ]);
    }
}
