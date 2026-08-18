<?php

declare(strict_types=1);

namespace App\Accounting\Read;

use InvalidArgumentException;

final readonly class ReadQuery
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 200;

    public function __construct(
        public int $limit = self::DEFAULT_LIMIT,
        public ?Cursor $cursor = null,
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('limit moet tussen 1 en '.self::MAX_LIMIT.' liggen.');
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<string>  $allowedExtra  endpoint-eigen parameters (`type`, `kind`)
     */
    public static function fromRequest(array $query, array $allowedExtra = []): self
    {
        $allowed = ['limit', 'cursor', ...$allowedExtra];
        $unknown = array_diff(array_keys($query), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Onbekende query-parameter(s): '.implode(', ', $unknown).'. Ondersteund: '.implode(', ', $allowed).'.'
            );
        }

        return new self(
            limit: (int) ($query['limit'] ?? self::DEFAULT_LIMIT),
            cursor: isset($query['cursor']) ? Cursor::decode((string) $query['cursor']) : null,
        );
    }
}
