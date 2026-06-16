<?php

declare(strict_types=1);

namespace App\Support\Exact;

use Emeq\ExactApi\Exceptions\AuthenticationException;
use Emeq\ExactApi\Exceptions\NotFoundException;
use Emeq\ExactApi\Exceptions\RateLimitException;
use Emeq\ExactApi\Exceptions\ServerException;
use Emeq\ExactApi\Exceptions\ValidationException;
use Saloon\Exceptions\Request\FatalRequestException;
use Throwable;

/**
 * Mapt Exact-SDK-/Saloon-exceptions uit een pass-through-call naar een Hub-HTTP-
 * response. Spiegelt App\Support\Snelstart\UpstreamErrorMapper (dedup naar een
 * gedeelde mapper = uitgestelde A2). 401/403 worden bewust naar 502 gemapt om de
 * Exact-auth-state niet te onthullen.
 *
 * @phpstan-type MappedError array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string}
 */
final class UpstreamErrorMapper
{
    /**
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string}
     */
    public static function mapException(Throwable $exception): array
    {
        if ($exception instanceof AuthenticationException) {
            return [
                'status' => 502,
                'body' => [
                    'error' => 'upstream_error',
                    'message' => 'Upstream auth failed',
                    'upstream_status' => 401,
                    'upstream_detail' => 'authentication_failed',
                ],
                'headers' => [],
                'short_code' => 'exact_auth',
            ];
        }

        if ($exception instanceof ServerException) {
            return [
                'status' => 502,
                'body' => [
                    'error' => 'upstream_error',
                    'message' => 'Upstream returned server error',
                    'upstream_status' => self::extractStatusFromMessage($exception->getMessage(), 500),
                    'upstream_detail' => 'server_error',
                ],
                'headers' => [],
                'short_code' => 'exact_5xx',
            ];
        }

        if ($exception instanceof ValidationException) {
            return [
                'status' => 400,
                'body' => [
                    'error' => 'upstream_validation',
                    'message' => $exception->getMessage(),
                    'upstream_status' => 400,
                ],
                'headers' => [],
                'short_code' => null,
            ];
        }

        if ($exception instanceof NotFoundException) {
            return [
                'status' => 404,
                'body' => [
                    'error' => 'upstream_not_found',
                    'message' => $exception->getMessage(),
                    'upstream_status' => 404,
                ],
                'headers' => [],
                'short_code' => null,
            ];
        }

        if ($exception instanceof RateLimitException) {
            $headers = [];
            if ($exception->retryAfterSeconds !== null) {
                $headers['Retry-After'] = (string) $exception->retryAfterSeconds;
            }

            return [
                'status' => 429,
                'body' => [
                    'error' => 'upstream_rate_limited',
                    'message' => $exception->getMessage(),
                    'upstream_status' => 429,
                ],
                'headers' => $headers,
                'short_code' => null,
            ];
        }

        if ($exception instanceof FatalRequestException) {
            return [
                'status' => 504,
                'body' => [
                    'error' => 'upstream_timeout',
                    'message' => 'Upstream did not respond in time',
                    'upstream_status' => 0,
                ],
                'headers' => [],
                'short_code' => 'exact_timeout',
            ];
        }

        return [
            'status' => 502,
            'body' => [
                'error' => 'upstream_error',
                'message' => 'Unexpected upstream failure',
                'upstream_status' => 0,
                'upstream_detail' => 'unknown',
            ],
            'headers' => [],
            'short_code' => 'exact_unknown',
        ];
    }

    private static function extractStatusFromMessage(string $message, int $default): int
    {
        if (preg_match('/HTTP\s+(\d{3})/', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        return $default;
    }
}
