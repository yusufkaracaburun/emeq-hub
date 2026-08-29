<?php

declare(strict_types=1);

namespace App\Integrations\DataForSeo\Webhooks;

use App\Integrations\Contracts\ResolvesCanonicalEvent;
use App\Integrations\Webhooks\CanonicalEvent;

final class DataForSeoEventResolver implements ResolvesCanonicalEvent
{
    /** @param array<string, mixed> $payload */
    public function resolve(array $payload): ?string
    {
        return CanonicalEvent::UNMAPPED;
    }
}
