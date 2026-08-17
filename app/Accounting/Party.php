<?php

declare(strict_types=1);

namespace App\Accounting;

/**
 * Debiteur (afnemer) of crediteur (leverancier) op een FinancialDocument. Het
 * canonical model draagt geen provider-GUID's — de adapter resolvet die, behalve
 * wanneer de consumer er zelf één pint via {@see self::$relationId}.
 *
 * Naast de sleutelvelden draagt de party een optionele relatiekaart (KvK, adres,
 * contactgegevens). Die is alleen van belang wanneer de Hub de relatie aanmaakt:
 * bestaat 'ie al, dan is de administratie leidend en raakt de Hub 'm niet aan.
 *
 * `kind` zegt wélke sleutels bestaan, niet wat de Hub mag doen: een `company`
 * draagt KvK of btw-nummer, een `person` heeft geen van beide en leunt volledig
 * op de mirror. Zie `.docs/decisions/relation-resolution-ladder.md`.
 */
final readonly class Party
{
    public const KIND_COMPANY = 'company';

    public const KIND_PERSON = 'person';

    public function __construct(
        public string $role,
        public string $name,
        public string $externalId,
        public string $kind = self::KIND_COMPANY,
        public ?string $vatNumber = null,
        public ?string $iban = null,
        public ?string $relationId = null,
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

    public function isCompany(): bool
    {
        return $this->kind !== self::KIND_PERSON;
    }

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
            externalId: (string) ($data['external_id'] ?? ''),
            kind: (string) ($data['kind'] ?? self::KIND_COMPANY),
            vatNumber: isset($data['vat_number']) ? (string) $data['vat_number'] : null,
            iban: isset($data['iban']) ? (string) $data['iban'] : null,
            relationId: $text('relation_id'),
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
