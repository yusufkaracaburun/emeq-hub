<?php

declare(strict_types=1);

namespace App\Integrations\Exact\Errors;

use App\Integrations\Contracts\MapsUpstreamExceptions;
use Emeq\ExactApi\Exceptions\AuthenticationException;
use Emeq\ExactApi\Exceptions\NotFoundException;
use Emeq\ExactApi\Exceptions\RateLimitException;
use Emeq\ExactApi\Exceptions\RequestTooBroadException;
use Emeq\ExactApi\Exceptions\ServerException;
use Emeq\ExactApi\Exceptions\ValidationException;
use Saloon\Exceptions\Request\FatalRequestException;
use Throwable;

/** @phpstan-type MappedError array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string} */
final class UpstreamErrorMapper implements MapsUpstreamExceptions
{
    /** @return array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string} */
    public static function mapException(Throwable $exception): array
    {
        if ($exception instanceof AuthenticationException) {
            $forbidden = $exception->apiStatus === 403;

            return [
                'status' => 502,
                'body' => [
                    'error' => 'upstream_error',
                    'message' => 'Upstream auth failed',
                    'upstream_status' => $exception->apiStatus ?? 401,
                    'upstream_detail' => $forbidden ? 'forbidden' : 'authentication_failed',
                ],
                'headers' => [],
                'short_code' => $forbidden ? 'exact_forbidden' : 'exact_auth',
            ];
        }

        if ($exception instanceof RequestTooBroadException) {
            return [
                'status' => 504,
                'body' => [
                    'error' => 'upstream_request_too_broad',
                    'message' => 'Exact weigerde de request als te breed — verfijn de $filter/$select of gebruik de sync-endpoints.',
                    'upstream_status' => 408,
                ],
                'headers' => [],
                'short_code' => 'exact_request_too_broad',
            ];
        }

        if ($exception instanceof ServerException) {
            if ($exception->status === 503) {
                $headers = [];
                if ($exception->retryAfterSeconds !== null) {
                    $headers['Retry-After'] = (string) $exception->retryAfterSeconds;
                }

                return [
                    'status' => 503,
                    'body' => [
                        'error' => 'upstream_unavailable',
                        'message' => 'Exact is tijdelijk niet beschikbaar (onderhoud) — probeer later opnieuw.',
                        'upstream_status' => 503,
                    ],
                    'headers' => $headers,
                    'short_code' => 'exact_unavailable',
                ];
            }

            $rawMessage = self::extractODataMessage($exception->rawBody);

            if ($rawMessage !== null) {
                $humanized = self::humanizeExactMessage($rawMessage);

                $body = [
                    'error' => 'upstream_rejected',
                    'message' => $humanized,
                    'upstream_status' => $exception->status,
                    'upstream_detail' => 'rejected',
                ];

                if ($humanized !== $rawMessage) {
                    $body['provider_message'] = $rawMessage;
                }

                return [
                    'status' => 422,
                    'body' => $body,
                    'headers' => [],
                    'short_code' => 'exact_rejected',
                ];
            }

            return [
                'status' => 502,
                'body' => [
                    'error' => 'upstream_error',
                    'message' => 'Upstream returned server error',
                    'upstream_status' => $exception->status,
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
            $headers = $exception->rateLimitHeaders;
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

    private static function humanizeExactMessage(string $raw): string
    {
        $haystack = mb_strtolower($raw);

        if (str_contains($haystack, 'boekjaar')) {
            return 'De administratie kent geen boekperiode voor de datum van dit document, '
                .'dus de boeking is geweigerd. Open het boekjaar in de administratie, of '
                .'geef het document een datum binnen een boekjaar dat openstaat. '
                .'Controleer het document vooraf om te zien welk bereik wél boekbaar is.';
        }

        if (str_contains($haystack, 'btw-nummer') || str_contains($haystack, 'controlecijfer')) {
            return 'Het btw-nummer is ongeldig. Controleer het en probeer opnieuw — '
                .'een Nederlands btw-nummer heeft de vorm NL + 9 cijfers + B + 2 cijfers '
                .'(bijvoorbeeld NL000099998B57).';
        }

        return $raw;
    }

    private static function extractODataMessage(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        /** @var array{error?: array{message?: array{value?: string}}}|null $decoded */
        $decoded = json_decode($body, true);

        $value = $decoded['error']['message']['value'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
