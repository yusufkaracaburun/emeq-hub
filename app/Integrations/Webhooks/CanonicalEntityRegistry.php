<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use App\Enums\Provider;
use App\Integrations\Contracts\ResolvesCanonicalEntity;

/**
 * Welke {@see ResolvesCanonicalEntity} hoort bij welke provider. Spiegel van
 * {@see CanonicalEventRegistry} en de andere provider-registries.
 *
 * Een provider zonder resolver levert overal `null` in plaats van een fout —
 * dezelfde zachte terugval als bij het canonieke event.
 */
final class CanonicalEntityRegistry
{
    /** @var array<string, class-string<ResolvesCanonicalEntity>> */
    private array $resolvers = [];

    /**
     * @param  class-string<ResolvesCanonicalEntity>  $resolver
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
    public function entityIdFor(Provider $provider, array $payload): ?string
    {
        return $this->resolver($provider)?->entityId($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function actionFor(Provider $provider, array $payload): ?string
    {
        return $this->resolver($provider)?->action($payload);
    }

    private function resolver(Provider $provider): ?ResolvesCanonicalEntity
    {
        $resolver = $this->resolvers[$provider->value] ?? null;

        return $resolver === null ? null : app($resolver);
    }
}
