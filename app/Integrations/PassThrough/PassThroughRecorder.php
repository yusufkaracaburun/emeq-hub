<?php

declare(strict_types=1);

namespace App\Integrations\PassThrough;

use App\Enums\Provider;
use App\Models\PassThroughCall;

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

        PassThroughCall::create([...$extra, ...$attributes]);
    }

    /** @param  array<string, mixed>|null  $body */
    private static function fingerprint(?array $body): ?string
    {
        if ($body === null || $body === []) {
            return null;
        }

        return substr(hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)), 0, 12);
    }
}
