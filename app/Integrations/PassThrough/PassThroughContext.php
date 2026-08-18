<?php

declare(strict_types=1);

namespace App\Integrations\PassThrough;

use App\Enums\Provider;

final class PassThroughContext
{
    /**
     * @param  string  $path  endpoint-template zonder query-string of concreet id
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $extra  kolommen die maar één stroom kent
     */
    public function __construct(
        public readonly Provider $provider,
        public readonly int $consumerId,
        public readonly ?int $accountId,
        public readonly ?int $connectionId,
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly ?array $body = null,
        public readonly ?string $direction = null,
        public readonly array $extra = [],
    ) {}
}
