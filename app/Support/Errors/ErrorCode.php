<?php

declare(strict_types=1);

namespace App\Support\Errors;

/**
 * Provider-onafhankelijke foutcategorie naast de bestaande `error`-sleutel.
 *
 * Een consumer die één keer integreert wil kunnen branchen zonder ~50 losse
 * string-codes te kennen, en zonder Exact's foutvormen te snappen. `error` blijft
 * exact wat hij was — dit komt ernáást, zodat niets breekt.
 *
 * De categorie volgt in de regel uit de HTTP-status. Alleen waar de status minder
 * zegt dan de code staat een override in {@see self::OVERRIDES}. Dat scheelt een
 * tabel met vijftig regels die bij elke nieuwe code bijgewerkt moet worden.
 */
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

    /**
     * Onze eigen fout, niet die van de partner. Bewust apart van PROVIDER_ERROR:
     * een consumer die daar geen onderscheid in kan maken, gaat de verkeerde kant
     * op zoeken.
     */
    case InternalError = 'INTERNAL_ERROR';

    /**
     * Codes waar de status de lading niet dekt.
     *
     * @var array<string, string>
     */
    private const OVERRIDES = [
        // 422 die geen invoerfout is maar een ontbrekende koppeling.
        'mapping_failed' => self::ReferenceMappingMissing->value,
        // 422 die zegt "deze provider kan dit niet".
        'sync_unsupported' => self::UnsupportedCapability->value,
        // 422 waarbij de partner functioneel weigerde — niet ónze validatie.
        'upstream_rejected' => self::ProviderError->value,
        'upstream_validation' => self::ProviderError->value,
        // 422 die in werkelijkheid een sleutelconflict is.
        'idempotency_key_reuse' => self::Conflict->value,
        // 502 die een gemaskeerde partner-auth-fout is, geen storing.
        'upstream_auth_failed' => self::ProviderError->value,
    ];

    /**
     * @var array<int, string>
     */
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

    /**
     * Of de consumer het opnieuw mag proberen met exact hetzelfde request.
     * Een conflict of validatiefout retryen levert alleen dezelfde fout op.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimited, self::ProviderUnavailable, self::InternalError => true,
            default => false,
        };
    }
}
