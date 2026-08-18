<?php

declare(strict_types=1);

namespace App\Accounting;

final readonly class TaxCode
{
    /** @param  array<string, mixed>  $attributes */
    public function __construct(
        public string $id,
        public string $code,
        public ?string $name = null,
        public ?float $rate = null,
        public array $attributes = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'rate' => $this->rate,
            'attributes' => (object) $this->attributes,
        ];
    }
}
