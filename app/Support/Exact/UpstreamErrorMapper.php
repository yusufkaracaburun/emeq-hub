<?php

declare(strict_types=1);

namespace App\Support\Exact;

use Emeq\ExactApi\Exceptions\AuthenticationException;
use Emeq\ExactApi\Exceptions\NotFoundException;
use Emeq\ExactApi\Exceptions\RateLimitException;
use Emeq\ExactApi\Exceptions\RequestTooBroadException;
use Emeq\ExactApi\Exceptions\ServerException;
use Emeq\ExactApi\Exceptions\ValidationException;
use Saloon\Exceptions\Request\FatalRequestException;
use Throwable;

/**
 * Mapt Exact-SDK-/Saloon-exceptions uit een pass-through-call naar een Hub-HTTP-
 * response. Spiegelt App\Support\Snelstart\UpstreamErrorMapper (dedup naar een
 * gedeelde mapper = uitgestelde A2).
 *
 * Mask-besluit (#9): 401 én 403 blijven naar 502 gemaskeerd zodat de upstream
 * auth-state niet naar de consumer lekt. Het operator-actionable onderscheid
 * (403 = scope/division/rechten-fix vs 401 = token vervangen) landt in
 * `pass_through_calls.upstream_error` via een eigen short_code.
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
            // 503 = Exact-onderhoud (04:00–04:30 CET) of Akamai-block: geef 503 + Retry-After
            // door zodat de consumer gericht wacht i.p.v. een blinde 502 te zien.
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

            // Een 5xx mét functionele OData-melding (`error.message.value`, dezelfde melding
            // die de boekhouder in de Exact-UI ziet) is een permanente afwijzing — niet
            // retryable. Geef 422: een 4xx laat Cloudflare de body ongemoeid, dus de consument
            // ziet de échte reden i.p.v. een generieke gateway-pagina.
            $rawMessage = self::extractODataMessage($exception->rawBody);

            if ($rawMessage !== null) {
                $humanized = self::humanizeExactMessage($rawMessage);

                $body = [
                    'error' => 'upstream_rejected',
                    'message' => $humanized,
                    'upstream_status' => $exception->status,
                    'upstream_detail' => 'rejected',
                ];

                // Bewaar de rauwe Exact-tekst voor developer-traceability zodra we 'm
                // hebben her-formuleerd (anders is `message` zelf al de bron).
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

            // Geen functionele melding → infra-/gateway-5xx: echt transient, retryable.
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

    /**
     * Vertaalt een bekende functionele Exact-melding naar een consument-vriendelijke,
     * partner-neutrale uitleg (een gebruiker die Exact niet kent moet 'm snappen).
     * Onbekende meldingen gaan ongewijzigd door — nooit info verbergen; de rauwe tekst
     * blijft via `provider_message` bewaard wanneer we wél her-formuleren.
     */
    private static function humanizeExactMessage(string $raw): string
    {
        $haystack = mb_strtolower($raw);

        // Exact keurt het btw-nummer af (ongeldig controlecijfer / verkeerd formaat).
        if (str_contains($haystack, 'btw-nummer') || str_contains($haystack, 'controlecijfer')) {
            return 'Het btw-nummer is ongeldig. Controleer het en probeer opnieuw — '
                .'een Nederlands btw-nummer heeft de vorm NL + 9 cijfers + B + 2 cijfers '
                .'(bijvoorbeeld NL000099998B57).';
        }

        return $raw;
    }

    /**
     * Haalt Exact's OData-foutmelding (`error.message.value`) uit een ruwe response-body.
     */
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
