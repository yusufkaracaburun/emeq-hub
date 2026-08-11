<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * Canonieke btw-code van de gekoppelde administratie.
 *
 * `rate` is het percentage als getal (21.0, niet 0.21) — de partner-notatie wordt in
 * de adapter genormaliseerd. Null wanneer de partner het niet meelevert; dat is
 * eerlijker dan 0.0, want 0% bestaat ook echt.
 */
final readonly class TaxCode
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $id,
        public string $code,
        public ?string $name = null,
        public ?float $rate = null,
        public array $attributes = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
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
