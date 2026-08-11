<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use Throwable;

/**
 * Vertaalt de exceptions van één partner-SDK naar een Hub-HTTP-respons.
 *
 * Elke provider heeft z'n eigen exception-hiërarchie, dus elke provider heeft
 * z'n eigen mapper. Dit contract bestaat zodat provider-neutrale code (de
 * accounting-runner, de canonieke lees-endpoints) de juiste mapper kan opvragen
 * bij {@see UpstreamErrorMapperRegistry} in plaats van er één hard te importeren.
 */
interface MapsUpstreamExceptions
{
    /**
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string}
     */
    public static function mapException(Throwable $exception): array;
}
