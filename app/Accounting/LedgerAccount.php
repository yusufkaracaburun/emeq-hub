<?php

declare(strict_types=1);

namespace App\Accounting;

final readonly class LedgerAccount
{
    /** @param  array<string, mixed>  $attributes  Provider-specifieke extra's, ongeïnterpreteerd. */
    public function __construct(
        public string $id,
        public string $code,
        public ?string $name = null,
        public array $attributes = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'attributes' => (object) $this->attributes,
        ];
    }
}
