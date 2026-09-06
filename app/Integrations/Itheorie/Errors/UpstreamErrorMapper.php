<?php

declare(strict_types=1);

namespace App\Integrations\Itheorie\Errors;

use App\Integrations\Contracts\MapsUpstreamExceptions;
use Emeq\ItheorieApi\Enums\ErrorKind;
use Emeq\ItheorieApi\Exceptions\ItheorieException;
use Saloon\Exceptions\Request\FatalRequestException;
use Throwable;

final class UpstreamErrorMapper implements MapsUpstreamExceptions
{
    /** @return array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string} */
    public static function mapException(Throwable $exception): array
    {
        if ($exception instanceof ItheorieException) {
            return self::mapPartnerError($exception);
        }

        if ($exception instanceof FatalRequestException) {
            return [
                'status' => 504,
                'body' => [
                    'error' => 'upstream_timeout',
                    'message' => 'iTheorie did not respond in time',
                    'upstream_status' => 0,
                ],
                'headers' => [],
                'short_code' => 'itheorie_timeout',
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
            'short_code' => 'itheorie_unknown',
        ];
    }

    /**
     * Broker-, token- en reseller-fouten zijn de credential van de Hub zelf, niet die
     * van de consumer. Die komen daarom terug als 502 en nooit als 401 of 403.
     *
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string}
     */
    private static function mapPartnerError(ItheorieException $exception): array
    {
        [$status, $error, $shortCode] = match ($exception->kind) {
            ErrorKind::Validation => [422, 'validation_failed', 'itheorie_validation'],
            ErrorKind::NotFound => [404, 'not_found', 'itheorie_not_found'],
            ErrorKind::BadRequest => [400, 'bad_request', 'itheorie_bad_request'],
            ErrorKind::ServiceUnavailable => [503, 'upstream_unavailable', 'itheorie_unavailable'],
            ErrorKind::Token, ErrorKind::Authentication => [502, 'upstream_auth_failed', 'itheorie_auth'],
            ErrorKind::Forbidden, ErrorKind::Reseller => [502, 'upstream_config_error', 'itheorie_config'],
            ErrorKind::Unknown => [502, 'upstream_error', 'itheorie_error'],
        };

        $body = [
            'error' => $error,
            'message' => $exception->getMessage(),
            'upstream_status' => $exception->status,
            'upstream_detail' => $exception->partnerCode !== 0 ? (string) $exception->partnerCode : null,
        ];

        if ($exception->violations !== []) {
            $body['violations'] = $exception->violations;
        }

        return [
            'status' => $status,
            'body' => $body,
            'headers' => [],
            'short_code' => $shortCode,
        ];
    }
}
