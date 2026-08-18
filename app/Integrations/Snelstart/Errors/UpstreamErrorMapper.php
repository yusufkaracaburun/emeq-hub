<?php

declare(strict_types=1);

namespace App\Integrations\Snelstart\Errors;

use App\Integrations\Contracts\MapsUpstreamExceptions;
use Emeq\SnelstartApi\Exceptions\AuthenticationException;
use Emeq\SnelstartApi\Exceptions\NotFoundException;
use Emeq\SnelstartApi\Exceptions\RateLimitException;
use Emeq\SnelstartApi\Exceptions\ServerException;
use Emeq\SnelstartApi\Exceptions\ValidationException;
use Saloon\Exceptions\Request\FatalRequestException;
use Throwable;

final class UpstreamErrorMapper implements MapsUpstreamExceptions
{
    /** @return array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string} */
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
                'short_code' => 'snelstart_auth',
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
                'short_code' => 'snelstart_5xx',
            ];
        }

        if ($exception instanceof ValidationException) {
            return [
                'status' => 400,
                'body' => [
                    'error' => 'upstream_validation',
                    'message' => $exception->getMessage(),
                    'upstream_status' => 400,
                    'error_codes' => $exception->errorCodes,
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
                'short_code' => 'snelstart_timeout',
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
            'short_code' => 'snelstart_unknown',
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
