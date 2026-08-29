<?php

declare(strict_types=1);

namespace App\Integrations\DataForSeo\Errors;

use App\Integrations\Contracts\MapsUpstreamExceptions;
use Emeq\DataForSeoApi\Exceptions\DataForSeoTaskException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

final class UpstreamErrorMapper implements MapsUpstreamExceptions
{
    /** @return array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string} */
    public static function mapException(Throwable $exception): array
    {
        if ($exception instanceof DataForSeoTaskException) {
            return [
                'status' => 502,
                'body' => [
                    'error' => 'upstream_error',
                    'message' => $exception->statusMessage,
                    'upstream_status' => $exception->statusCode,
                    'upstream_detail' => $exception->statusMessage,
                ],
                'headers' => [],
                'short_code' => 'dataforseo_task_error',
            ];
        }

        if ($exception instanceof FatalRequestException) {
            return [
                'status' => 504,
                'body' => [
                    'error' => 'upstream_timeout',
                    'message' => 'DataForSEO did not respond in time',
                    'upstream_status' => 0,
                ],
                'headers' => [],
                'short_code' => 'dataforseo_timeout',
            ];
        }

        if ($exception instanceof RequestException) {
            $response = $exception->getResponse();
            $status = $response->status();
            $body = $response->json() ?: [];

            $shortCode = match (true) {
                $status === 401 || $status === 403 => 'dataforseo_auth',
                $status === 429 => 'dataforseo_rate_limited',
                $status >= 500 => 'dataforseo_5xx',
                default => 'dataforseo_error',
            };

            $statusMessage = is_array($body) ? ($body['status_message'] ?? null) : null;

            return [
                'status' => $status >= 500 ? 502 : $status,
                'body' => [
                    'error' => match (true) {
                        $status === 401 || $status === 403 => 'upstream_auth_failed',
                        $status === 429 => 'upstream_rate_limited',
                        $status >= 500 => 'upstream_error',
                        default => 'upstream_error',
                    },
                    'message' => $statusMessage ?? 'DataForSEO upstream error',
                    'upstream_status' => $status,
                    'upstream_detail' => $statusMessage,
                ],
                'headers' => [],
                'short_code' => $shortCode,
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
            'short_code' => 'dataforseo_unknown',
        ];
    }
}
