<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * Canonieke grootboekrekening.
 *
 * `id` is een **ondoorzichtige** handle binnen deze koppeling — gebruik 'm om terug
 * te verwijzen, lees er geen betekenis in. Vandaag is het de partner-identiteit; dat
 * is geen belofte. `code` is wél betekenisvol: dat is het rekeningnummer dat de
 * boekhouder kent en dat je in `line.category` kunt terugleggen.
 */
final readonly class LedgerAccount
{
    /**
     * @param  array<string, mixed>  $attributes  Provider-specifieke extra's, ongeïnterpreteerd.
     */
    public function __construct(
        public string $id,
        public string $code,
        public ?string $name = null,
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
            'attributes' => (object) $this->attributes,
        ];
    }
}
