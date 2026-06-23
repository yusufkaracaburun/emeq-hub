<?php

declare(strict_types=1);

namespace App\Support\Mollie;

use App\Exceptions\Mollie\MissingPartnerTokenException;
use Emeq\MollieApi\Exceptions\AuthenticationException;
use Emeq\MollieApi\Exceptions\MollieException;
use Emeq\MollieApi\Exceptions\NotFoundException;
use Emeq\MollieApi\Exceptions\RateLimitException;
use Emeq\MollieApi\Exceptions\ServerException;
use Emeq\MollieApi\Exceptions\ValidationException;
use Throwable;

/**
 * Mapt Mollie-SDK-exceptions (Emeq\MollieApi\Exceptions\*) naar
 * een Hub-HTTP-response (status + JSON-body + extra headers + audit-short-code).
 *
 * Policy-bron: 05a-CONTEXT.md §<decisions> D-13 + .docs/decisions/mollie-passthrough-api.md.
 * 401/403 worden bewust naar 502 cloaked om Mollie-auth-state niet te
 * onthullen aan de Consumer (threat T-05a-06).
 */
final class UpstreamErrorMapper
{
    /**
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string}
     */
    public static function mapException(Throwable $exception): array
    {
        if ($exception instanceof MissingPartnerTokenException) {
            return [
                'status' => 503,
                'body' => [
                    'error' => 'partner_token_missing',
                    'message' => 'Mollie partner-access-token niet geconfigureerd op Hub. Contact Emeq-staff.',
                    'upstream_status' => 0,
                ],
                'headers' => [],
                'short_code' => 'partner_token_missing',
            ];
        }

        if ($exception instanceof ValidationException) {
            $body = [
                'error' => 'validation_failed',
                'message' => $exception->getMessage(),
                'upstream_status' => 422,
            ];

            if (($field = $exception->getField()) !== null) {
                $body['field'] = $field;
            }

            return [
                'status' => 422,
                'body' => $body,
                'headers' => [],
                'short_code' => null,
            ];
        }

        if ($exception instanceof AuthenticationException) {
            return [
                'status' => 502,
                'body' => [
                    'error' => 'mollie_auth_failed',
                    'message' => 'Upstream auth failed',
                    'upstream_status' => 401,
                    'upstream_detail' => 'authentication_failed',
                ],
                'headers' => [],
                'short_code' => 'mollie_auth',
            ];
        }

        if ($exception instanceof NotFoundException) {
            return [
                'status' => 404,
                'body' => [
                    'error' => 'not_found',
                    'message' => $exception->getMessage(),
                    'upstream_status' => 404,
                ],
                'headers' => [],
                'short_code' => null,
            ];
        }

        if ($exception instanceof RateLimitException) {
            // Emeq\MollieApi\Exceptions\RateLimitException exposeert (nog) geen
            // retry-after-getter; we laten de header leeg. Mollie's docs zeggen
            // dat clients een default-backoff van 60s mogen hanteren.
            return [
                'status' => 429,
                'body' => [
                    'error' => 'rate_limited',
                    'message' => $exception->getMessage(),
                    'upstream_status' => 429,
                ],
                'headers' => [],
                'short_code' => null,
            ];
        }

        if ($exception instanceof ServerException) {
            return [
                'status' => 502,
                'body' => [
                    'error' => 'mollie_unavailable',
                    'message' => 'Mollie returned 5xx',
                    'upstream_status' => 503,
                    'upstream_detail' => 'server_error',
                ],
                'headers' => [],
                'short_code' => 'mollie_5xx',
            ];
        }

        // MollieException (base) + onverwachte \Throwable → catch-all.
        return [
            'status' => 502,
            'body' => [
                'error' => 'mollie_error',
                'message' => 'Unexpected upstream failure',
                'upstream_status' => 0,
                'upstream_detail' => 'unknown',
            ],
            'headers' => [],
            'short_code' => 'mollie_unknown',
        ];
    }
}
