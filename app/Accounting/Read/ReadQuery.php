<?php

declare(strict_types=1);

namespace App\Accounting\Read;

use InvalidArgumentException;

/**
 * Paginatie-verzoek voor een canoniek lees-endpoint.
 *
 * Cursor-based, niet offset. Exact OData pagineert met `$skiptoken` en niet met een
 * offset, dus `?page=3` zou een leugen zijn die we in de Hub zouden moeten
 * nabouwen. De cursor is voor de consumer **ondoorzichtig**: geef 'm terug zoals je
 * 'm kreeg, lees er niets in.
 */
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
     */
    public static function fromRequest(array $query): self
    {
        return new self(
            limit: (int) ($query['limit'] ?? self::DEFAULT_LIMIT),
            cursor: isset($query['cursor']) ? Cursor::decode((string) $query['cursor']) : null,
        );
    }
}
