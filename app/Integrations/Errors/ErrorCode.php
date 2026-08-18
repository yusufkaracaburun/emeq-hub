<?php

declare(strict_types=1);

namespace App\Integrations\Errors;

enum ErrorCode: string
{
    case ValidationError = 'VALIDATION_ERROR';
    case AuthenticationError = 'AUTHENTICATION_ERROR';
    case AuthorizationError = 'AUTHORIZATION_ERROR';
    case RateLimited = 'RATE_LIMITED';
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';
    case Conflict = 'CONFLICT';
    case ProviderUnavailable = 'PROVIDER_UNAVAILABLE';
    case UnsupportedCapability = 'UNSUPPORTED_CAPABILITY';
    case ReferenceMappingMissing = 'REFERENCE_MAPPING_MISSING';
    case ProviderError = 'PROVIDER_ERROR';

    case InternalError = 'INTERNAL_ERROR';

    /** @var array<string, string> */
    private const OVERRIDES = [
        'mapping_failed' => self::ReferenceMappingMissing->value,
        'sync_unsupported' => self::UnsupportedCapability->value,
        'unsupported_capability' => self::UnsupportedCapability->value,
        'upstream_rejected' => self::ProviderError->value,
        'upstream_validation' => self::ProviderError->value,
        'idempotency_key_reuse' => self::Conflict->value,
        'upstream_auth_failed' => self::ProviderError->value,
    ];

    /** @var list<string> */
    private const RETRYABLE_CODES = [
        'idempotency_request_in_progress',
        'document_sync_in_progress',
    ];

    /** @var array<int, string> */
    private const BY_STATUS = [
        400 => self::ValidationError->value,
        401 => self::AuthenticationError->value,
        403 => self::AuthorizationError->value,
        404 => self::ResourceNotFound->value,
        405 => self::ValidationError->value,
        409 => self::Conflict->value,
        410 => self::ResourceNotFound->value,
        415 => self::ValidationError->value,
        422 => self::ValidationError->value,
        429 => self::RateLimited->value,
        500 => self::InternalError->value,
        502 => self::ProviderUnavailable->value,
        503 => self::ProviderUnavailable->value,
        504 => self::ProviderUnavailable->value,
    ];

    public static function for(int $status, ?string $error = null): self
    {
        if ($error !== null && isset(self::OVERRIDES[$error])) {
            return self::from(self::OVERRIDES[$error]);
        }

        if (isset(self::BY_STATUS[$status])) {
            return self::from(self::BY_STATUS[$status]);
        }

        return $status >= 500 ? self::InternalError : self::ValidationError;
    }

    public static function retryableFor(int $status, ?string $error = null): bool
    {
        if ($error !== null && in_array($error, self::RETRYABLE_CODES, true)) {
            return true;
        }

        return self::for($status, $error)->isRetryable();
    }

    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimited, self::ProviderUnavailable, self::InternalError => true,
            default => false,
        };
    }
}
