<?php

declare(strict_types=1);

namespace App\Accounting;

final class BookingWarnings
{
    /** @var list<array{code: string, message: string, context: array<string, mixed>}> */
    private array $items = [];

    /** @param  array<string, mixed>  $context */
    public function add(string $code, string $message, array $context = []): void
    {
        $this->items[] = ['code' => $code, 'message' => $message, 'context' => $context];
    }

    /** @return list<array{code: string, message: string, context: array<string, mixed>}> */
    public function all(): array
    {
        return $this->items;
    }

    public function flush(): void
    {
        $this->items = [];
    }
}
