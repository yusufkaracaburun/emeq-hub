<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * Debiteur (afnemer) of crediteur (leverancier) op een FinancialDocument. Het
 * canonical model draagt geen provider-GUID's — de adapter resolvet die.
 */
final readonly class Party
{
    public function __construct(
        public string $role,
        public string $name,
        public ?string $vatNumber = null,
        public ?string $iban = null,
        public ?string $externalId = null,
        public bool $createIfMissing = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            role: (string) $data['role'],
            name: (string) $data['name'],
            vatNumber: isset($data['vat_number']) ? (string) $data['vat_number'] : null,
            iban: isset($data['iban']) ? (string) $data['iban'] : null,
            externalId: isset($data['external_id']) ? (string) $data['external_id'] : null,
            createIfMissing: (bool) ($data['create_if_missing'] ?? false),
        );
    }
}
