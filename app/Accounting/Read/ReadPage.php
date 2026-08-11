<?php

declare(strict_types=1);

namespace App\Accounting\Read;

/**
 * Eén pagina canonieke resources plus de cursor naar de volgende.
 *
 * Bewust géén totaal-aantal: Exact OData levert dat niet zonder een tweede
 * `$count`-call, en een veld dat bij de ene provider klopt en bij de andere
 * ontbreekt is erger dan geen veld.
 *
 * @template T
 */
final readonly class ReadPage
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public array $items,
        public ?Cursor $nextCursor = null,
    ) {}

    /**
     * @return array{data: list<mixed>, next_cursor: ?string, has_more: bool}
     */
    public function toArray(callable $transform): array
    {
        return [
            'data' => array_map($transform, $this->items),
            'next_cursor' => $this->nextCursor?->encode(),
            'has_more' => $this->nextCursor !== null,
        ];
    }
}
