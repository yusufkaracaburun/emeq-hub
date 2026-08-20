<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Accounting\Contracts\AccountingTarget;
use App\Accounting\Contracts\EnrichesValidation;
use App\Accounting\Contracts\SyncsReferenceData;
use App\Accounting\Enums\Capability;
use App\Integrations\Exceptions\ProviderDisabledException;
use App\Models\Connection;
use App\Support\ProviderGate;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

final class AccountingTargetRegistry
{
    /** @var array<string, class-string<AccountingTarget>> */
    private array $targets = [];

    public function __construct(private readonly Container $container) {}

    /** @param  class-string<AccountingTarget>  $implementation */
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

        if (! $this->enabled($provider)) {
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

    public function enabled(string $provider): bool
    {
        return ProviderGate::enabled($provider);
    }

    /** @return list<Capability> */
    public function capabilitiesFor(Connection $connection): array
    {
        $implementation = $this->targets[$connection->provider->value] ?? null;

        if ($implementation === null) {
            return [];
        }

        return array_values(array_filter(
            Capability::cases(),
            static fn (Capability $capability): bool => is_a($implementation, $capability->contract(), allow_string: true),
        ));
    }

    /** @throws ProviderDisabledException */
    public function syncsReferenceData(Connection $connection): ?SyncsReferenceData
    {
        $target = $this->targetFor($connection);

        return $target instanceof SyncsReferenceData ? $target : null;
    }

    /** @throws ProviderDisabledException */
    public function enrichesValidation(Connection $connection): ?EnrichesValidation
    {
        $target = $this->targetFor($connection);

        return $target instanceof EnrichesValidation ? $target : null;
    }

    private function targetFor(Connection $connection): ?AccountingTarget
    {
        $provider = $connection->provider->value;

        return $this->supports($provider) ? $this->for($provider) : null;
    }
}
