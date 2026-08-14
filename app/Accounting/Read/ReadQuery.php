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
     * Onbekende parameters worden geweigerd in plaats van genegeerd: een consumer die
     * filtert op bijvoorbeeld `external_id` kreeg anders stil de ongefilterde lijst
     * terug — 200 met een leugen is het slechtste faalgedrag van de twee.
     *
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
