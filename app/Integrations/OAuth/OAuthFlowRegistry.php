<?php

namespace App\Integrations\OAuth;

use App\Integrations\Contracts\OAuthFlow;
use App\Integrations\Exceptions\ProviderDisabledException;
use App\Support\ProviderGate;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

final class OAuthFlowRegistry
{
    /** @var array<string, class-string<OAuthFlow>> */
    private array $providers = [];

    public function __construct(private readonly Container $container) {}

    /** @param  class-string<OAuthFlow>  $implementation */
    public function register(string $provider, string $implementation): void
    {
        $this->providers[$provider] = $implementation;
    }

    public function for(string $provider): OAuthFlow
    {
        if (! isset($this->providers[$provider])) {
            throw new InvalidArgumentException(
                "Geen OAuthFlow geregistreerd voor provider '{$provider}'."
            );
        }

        if (! ProviderGate::enabled($provider)) {
            throw new ProviderDisabledException($provider);
        }

        return $this->container->make($this->providers[$provider]);
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys($this->providers);
    }
}
