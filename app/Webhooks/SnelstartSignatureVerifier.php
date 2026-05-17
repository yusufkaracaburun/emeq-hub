<?php

declare(strict_types=1);

namespace App\Webhooks;

/**
 * HMAC-verifier voor Snelstart-webhook-ingress.
 *
 * Header-naam en algoritme zijn config-driven (services.snelstart.webhook_*).
 * Partner-respons 2026-05-17 bevestigde X-SnelStart-Signature + HMAC-SHA256 over
 * raw body, hex-encoded (CONTEXT 🔒 #1). Rotation-window via array van secrets
 * matcht subscription-key-pattern uit Snelstart (CONTEXT 🔒 #2).
 */
final class SnelstartSignatureVerifier
{
    /**
     * @param  string|string[]  $secrets  Eén secret of een array (rotation-window).
     */
    public static function verify(
        string $rawBody,
        ?string $headerValue,
        string|array $secrets,
        string $algo = 'sha256',
    ): bool {
        if ($headerValue === null || $headerValue === '') {
            return false;
        }

        $candidates = is_array($secrets) ? $secrets : [$secrets];
        $candidates = array_values(array_filter(
            $candidates,
            static fn (?string $secret): bool => is_string($secret) && $secret !== '',
        ));

        if ($candidates === []) {
            return false;
        }

        foreach ($candidates as $secret) {
            $expected = hash_hmac($algo, $rawBody, $secret);
            if (hash_equals($expected, $headerValue)) {
                return true;
            }
        }

        return false;
    }

    public static function sign(string $payload, string $secret, string $algo = 'sha256'): string
    {
        return hash_hmac($algo, $payload, $secret);
    }
}
