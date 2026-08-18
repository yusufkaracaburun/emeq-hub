<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use App\Models\Connection;
use Carbon\CarbonInterface;

interface DetectsHubOrigin
{
    /** @param  array<string, mixed>  $payload */
    public function hubAuthored(Connection $connection, array $payload): bool;

    /** @param  array<string, mixed>  $payload */
    public function hubLastWroteAt(Connection $connection, array $payload): ?CarbonInterface;
}
