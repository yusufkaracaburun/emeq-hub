<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use App\Models\Connection;
use Carbon\CarbonInterface;

interface DetectsHubOrigin
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function causedByHub(Connection $connection, array $payload): bool;

    /**
     * Wanneer de Hub deze entity voor het laatst zelf schreef, of `null` zonder
     * bekende schrijfactie. Los van {@see self::causedByHub()}: dat zegt "heeft de
     * Hub dit ooit geschreven", dit zegt "wanneer precies" — het gegeven dat een
     * consumer nodig heeft om een eigen echo te herkennen zonder te gokken.
     *
     * @param  array<string, mixed>  $payload
     */
    public function hubLastWroteAt(Connection $connection, array $payload): ?CarbonInterface;
}
