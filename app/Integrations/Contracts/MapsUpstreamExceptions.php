<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

use Throwable;

interface MapsUpstreamExceptions
{
    /** @return array{status: int, body: array<string, mixed>, headers: array<string, string>, short_code: ?string} */
    public static function mapException(Throwable $exception): array;
}
