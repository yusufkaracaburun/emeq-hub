<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Accounting\Contracts\AccountingTarget;
use App\OAuth\Exceptions\ProviderDisabledException;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Laravel\Pennant\Feature;

/**
 * Resolved de juiste AccountingTarget-adapter per provider. Spiegel van
 * OAuthFlowRegistry: register + dezelfde Pennant-kill-switch (`provider-{p}-enabled`).
 * Alleen boekhoud-providers worden geregistreerd — `supports()` onderscheidt zo
 * een accounting-Connection van bv. een Mollie-betaal-Connection.
 */
final class AccountingTargetRegistry
{
    /** @var array<string, class-string<AccountingTarget>> */
    private array $targets = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<AccountingTarget>  $implementation
     */
    public function register(string $provider, string $implementation): void
    {
        $this->targets[$provider] = $implementation;
    }

    public function for(string $provider): AccountingTarget
    {
        if (! isset($this->targets[$provider])) {
            throw new InvalidArgumentException(
                "Geen AccountingTarget geregistreerd voor provider '{$provider}'."
            );
        }

        if (! Feature::active("provider-{$provider}-enabled")) {
            throw new ProviderDisabledException($provider);
        }

        return $this->container->make($this->targets[$provider]);
    }

    public function supports(string $provider): bool
    {
        return isset($this->targets[$provider]);
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys($this->targets);
    }
}
