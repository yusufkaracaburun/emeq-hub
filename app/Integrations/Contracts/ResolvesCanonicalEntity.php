<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

interface ResolvesCanonicalEntity
{
    /** @param  array<string, mixed>  $payload */
    public function entityId(array $payload): ?string;

    /** @param  array<string, mixed>  $payload */
    public function action(array $payload): ?string;
}
