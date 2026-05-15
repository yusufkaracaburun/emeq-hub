<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Value-object met factory + discovery voor provider-credential-metadata.
 *
 * D-04: Connection-fingerprint, Filament-form-sections en revoke-visibility
 *       worden config-driven gemaakt; nieuwe provider toevoegen = nieuwe rij
 *       in config/hub-providers.php zonder Filament-code-wijziging.
 *
 * Bewuste keuze: final class + static factory (zelfde discovery-contract als
 * App\Sanctum\TokenAbilities::all()) en readonly properties (immutable).
 */
final class ProviderCredentialDescriptor
{
    /**
     * @param  list<string>  $encryptedFields  Connection-attributen die encrypted-credentials
     *                                         zijn voor deze provider; eerste element is de
     *                                         primary credential die fingerprint() hasht.
     */
    public function __construct(
        public readonly string $key,
        public readonly array $encryptedFields,
        public readonly string $primaryFingerprintLabel,
        public readonly ?string $oauthFlowKey,
    ) {}

    /**
     * @throws InvalidArgumentException Wanneer de provider niet in config/hub-providers.php staat.
     */
    public static function for(string $provider): self
    {
        /** @var array<string, mixed>|null $cfg */
        $cfg = config("hub-providers.{$provider}");

        if (! is_array($cfg)) {
            throw new InvalidArgumentException("Onbekende provider: {$provider}");
        }

        /** @var list<string> $encryptedFields */
        $encryptedFields = $cfg['encrypted_fields'];

        /** @var string $primaryLabel */
        $primaryLabel = $cfg['primary_label'];

        /** @var string|null $oauthFlowKey */
        $oauthFlowKey = $cfg['oauth_flow_key'] ?? null;

        return new self(
            key: $provider,
            encryptedFields: $encryptedFields,
            primaryFingerprintLabel: $primaryLabel,
            oauthFlowKey: $oauthFlowKey,
        );
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        /** @var array<string, mixed> $providers */
        $providers = config('hub-providers', []);

        return array_map(
            fn (string $key): self => self::for($key),
            array_keys($providers),
        );
    }
}
