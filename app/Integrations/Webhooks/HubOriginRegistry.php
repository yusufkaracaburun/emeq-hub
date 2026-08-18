<?php

declare(strict_types=1);

namespace App\Integrations\Webhooks;

use App\Enums\Provider;
use App\Integrations\Contracts\DetectsHubOrigin;
use App\Models\Connection;
use Carbon\CarbonInterface;

final class HubOriginRegistry
{
    /** @var array<string, class-string<DetectsHubOrigin>> */
    private array $detectors = [];

    /** @param  class-string<DetectsHubOrigin>  $detector */
    public function register(Provider $provider, string $detector): void
    {
        $this->detectors[$provider->value] = $detector;
    }

    public function supports(Provider $provider): bool
    {
        return isset($this->detectors[$provider->value]);
    }

    /** @param  array<string, mixed>  $payload */
    public function hubAuthored(Provider $provider, Connection $connection, array $payload): bool
    {
        $detector = $this->detectors[$provider->value] ?? null;

        if ($detector === null) {
            return false;
        }

        return app($detector)->hubAuthored($connection, $payload);
    }

    /** @param  array<string, mixed>  $payload */
    public function hubLastWroteAt(Provider $provider, Connection $connection, array $payload): ?CarbonInterface
    {
        $detector = $this->detectors[$provider->value] ?? null;

        if ($detector === null) {
            return null;
        }

        return app($detector)->hubLastWroteAt($connection, $payload);
    }
}
