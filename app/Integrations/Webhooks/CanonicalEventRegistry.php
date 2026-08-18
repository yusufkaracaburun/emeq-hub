<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use App\Enums\Provider;
use App\Integrations\Contracts\ResolvesCanonicalEvent;

final class CanonicalEventRegistry
{
    /** @var array<string, class-string<ResolvesCanonicalEvent>> */
    private array $resolvers = [];

    /** @param  class-string<ResolvesCanonicalEvent>  $resolver */
    public function register(Provider $provider, string $resolver): void
    {
        $this->resolvers[$provider->value] = $resolver;
    }

    public function supports(Provider $provider): bool
    {
        return isset($this->resolvers[$provider->value]);
    }

    /** @param  array<string, mixed>  $payload */
    public function eventFor(Provider $provider, array $payload): string
    {
        $resolver = $this->resolvers[$provider->value] ?? null;

        if ($resolver === null) {
            return CanonicalEvent::UNMAPPED;
        }

        return app($resolver)->resolve($payload) ?? CanonicalEvent::UNMAPPED;
    }
}
