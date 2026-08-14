<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * Debiteur (afnemer) of crediteur (leverancier) op een FinancialDocument. Het
 * canonical model draagt geen provider-GUID's — de adapter resolvet die.
 *
 * Naast de sleutelvelden draagt de party een optionele relatiekaart (KvK, adres,
 * contactgegevens). Die is alleen van belang wanneer de Hub de relatie aanmaakt:
 * bestaat 'ie al, dan is de administratie leidend en raakt de Hub 'm niet aan.
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
        public ?string $chamberOfCommerce = null,
        public ?string $addressLine1 = null,
        public ?string $addressLine2 = null,
        public ?string $postcode = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $country = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $website = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $text = static fn (string $key): ?string => isset($data[$key]) && trim((string) $data[$key]) !== ''
            ? trim((string) $data[$key])
            : null;

        return new self(
            role: (string) $data['role'],
            name: (string) $data['name'],
            vatNumber: isset($data['vat_number']) ? (string) $data['vat_number'] : null,
            iban: isset($data['iban']) ? (string) $data['iban'] : null,
            externalId: isset($data['external_id']) ? (string) $data['external_id'] : null,
            createIfMissing: (bool) ($data['create_if_missing'] ?? false),
            chamberOfCommerce: $text('chamber_of_commerce'),
            addressLine1: $text('address_line_1'),
            addressLine2: $text('address_line_2'),
            postcode: $text('postcode'),
            city: $text('city'),
            state: $text('state'),
            country: $text('country') === null ? null : strtoupper((string) $text('country')),
            email: $text('email'),
            phone: $text('phone'),
            website: $text('website'),
        );
    }
}
