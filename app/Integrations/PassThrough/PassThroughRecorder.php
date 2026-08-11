<?php

declare(strict_types=1);

namespace App\Integrations\PassThrough;

use App\Enums\Provider;
use App\Models\PassThroughCall;

/**
 * Schrijft de auditrij van één Consumer→Hub→Partner-call.
 *
 * `pass_through_calls` is het enige spoor van wat een Consumer via de Hub bij een
 * partner gedaan heeft. Die rij werd op zeven plekken met de hand samengesteld,
 * waarvan vijf byte-voor-byte gelijk op de providerwaarde na. Zeven kopieën van
 * een auditcontract betekent dat provider #4 er een achtste bijschrijft en dat
 * niemand merkt als daar een veld in ontbreekt.
 *
 * De privacy-eisen zitten hier, niet bij de aanroeper: `path` draagt het
 * endpoint-template zonder query-string of concrete id, `query_keys` alleen de
 * sleutels, en de request-body wordt gereduceerd tot een fingerprint.
 */
final class PassThroughRecorder
{
    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body  wordt tot een fingerprint gereduceerd; nooit opgeslagen
     * @param  float|null  $startedAt  `microtime(true)` bij aanvang; null → duur 0
     * @param  array<string, mixed>  $extra  kolommen die maar één stroom kent — Mollie Connect
     *                                       draagt `token_type` en `partner_token_fingerprint`
     */
    public function record(
        Provider $provider,
        int $consumerId,
        ?int $accountId,
        ?int $connectionId,
        string $method,
        string $path,
        int $status,
        ?string $responseBody,
        ?float $startedAt = null,
        array $query = [],
        ?array $body = null,
        ?string $upstreamError = null,
        ?string $direction = null,
        ?string $requestFingerprint = null,
        array $extra = [],
    ): void {
        $attributes = [
            'consumer_id' => $consumerId,
            'account_id' => $accountId,
            'connection_id' => $connectionId,
            'provider' => $provider->value,
            'method' => $method,
            'path' => $path,
            'query_keys' => $query !== [] ? implode(',', array_keys($query)) : null,
            'status' => $status,
            'duration_ms' => $startedAt === null ? 0 : (int) round((microtime(true) - $startedAt) * 1000),
            'request_fingerprint' => $requestFingerprint ?? self::fingerprint($body),
            'response_size_bytes' => $responseBody === null ? null : strlen($responseBody),
            'upstream_error' => $upstreamError,
            'response_body' => PassThroughCall::errorBody($status, $responseBody),
            'created_at' => now(),
        ];

        if ($direction !== null) {
            $attributes['direction'] = $direction;
        }

        PassThroughCall::create([...$attributes, ...$extra]);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private static function fingerprint(?array $body): ?string
    {
        if ($body === null || $body === []) {
            return null;
        }

        return substr(hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)), 0, 12);
    }
}
