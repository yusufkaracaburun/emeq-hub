<?php

declare(strict_types=1);

namespace App\Integrations\PassThrough;

/**
 * Wat er van de partner terugkwam, in de vorm waarin de Hub het doorgeeft.
 *
 * Twee soorten pass-through leveren dit aan: de SDK's die een rauwe HTTP-response
 * teruggeven (Exact, Snelstart — status en content-type komen van de partner) en de
 * SDK's die een resource-object teruggeven (Mollie — de Hub kiest zelf de status en
 * codeert naar JSON).
 */
final class UpstreamResult
{
    /**
     * @param  array<string, string>  $headers  extra headers naast Content-Type
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $contentType = 'application/json',
        public readonly array $headers = [],
    ) {}

    /**
     * @param  array<int|string, mixed>  $payload
     *
     * @throws \JsonException
     */
    public static function json(array $payload, int $status): self
    {
        return new self($status, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
