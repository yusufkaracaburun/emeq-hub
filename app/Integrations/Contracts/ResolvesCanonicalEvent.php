<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

interface ResolvesCanonicalEvent
{
    /** @param  array<string, mixed>  $payload */
    public function resolve(array $payload): ?string;
}
