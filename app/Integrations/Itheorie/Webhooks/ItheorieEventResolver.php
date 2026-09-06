<?php

declare(strict_types=1);

namespace App\Integrations\Itheorie\Webhooks;

use App\Integrations\Contracts\ResolvesCanonicalEvent;
use App\Integrations\Webhooks\CanonicalEvent;

final class ItheorieEventResolver implements ResolvesCanonicalEvent
{
    /** @param array<string, mixed> $payload */
    public function resolve(array $payload): ?string
    {
        return CanonicalEvent::UNMAPPED;
    }
}
