<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use App\Enums\Provider;
use App\Integrations\Contracts\ResolvesCanonicalEvent;

/**
 * Welke {@see ResolvesCanonicalEvent} hoort bij welke provider. Spiegel van de
 * andere provider-registries.
 *
 * Een provider zonder resolver levert {@see CanonicalEvent::UNMAPPED} in plaats
 * van een fout: een ontbrekende registratie mag een webhook niet laten sneuvelen.
 * Dat het ontbreekt hoort in CI op te vallen, niet in productie.
 */
final class CanonicalEventRegistry
{
    /** @var array<string, class-string<ResolvesCanonicalEvent>> */
    private array $resolvers = [];

    /**
     * @param  class-string<ResolvesCanonicalEvent>  $resolver
     */
    public function register(Provider $provider, string $resolver): void
    {
        $this->resolvers[$provider->value] = $resolver;
    }

    public function supports(Provider $provider): bool
    {
        return isset($this->resolvers[$provider->value]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function eventFor(Provider $provider, array $payload): string
    {
        $resolver = $this->resolvers[$provider->value] ?? null;

        if ($resolver === null) {
            return CanonicalEvent::UNMAPPED;
        }

        return app($resolver)->resolve($payload) ?? CanonicalEvent::UNMAPPED;
    }
}
