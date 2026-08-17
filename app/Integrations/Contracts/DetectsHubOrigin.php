<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use App\Models\Connection;
use Carbon\CarbonInterface;

interface DetectsHubOrigin
{
    /**
     * Of de Hub deze entity ooit zelf schreef. Auteurschap, geen causaliteit: een
     * handmatige correctie op een door de Hub geboekte factuur is óók `true`.
     *
     * @param  array<string, mixed>  $payload
     */
    public function hubAuthored(Connection $connection, array $payload): bool;

    /**
     * Wanneer de Hub deze entity voor het laatst zelf schreef, of `null` zonder
     * bekende schrijfactie. Los van {@see self::hubAuthored()}: dat zegt "ooit",
     * dit zegt "wanneer precies" — het gegeven dat een consumer nodig heeft om een
     * eigen echo te herkennen zonder te gokken.
     *
     * @param  array<string, mixed>  $payload
     */
    public function hubLastWroteAt(Connection $connection, array $payload): ?CarbonInterface;
}
