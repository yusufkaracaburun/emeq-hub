<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Validators;

use App\Accounting\Validation\Contracts\DocumentValidator;
use App\Accounting\Validation\Finding;
use App\Accounting\Validation\Geography\CountryResolver;
use App\Accounting\Validation\Severity;

/**
 * Vergelijkt het land afgeleid uit het BTW-nummer met dat uit het IBAN. Een mismatch
 * (bijv. Duits BTW-nummer op een Nederlands IBAN) wijst op een extractiefout of een
 * onverwachte constructie en wordt geflagd. De daadwerkelijke NL/EU/niet-EU-classificatie
 * voedt de VatTreatmentValidator.
 */
final class GeographyClassifier implements DocumentValidator
{
    public function validate(array $payload): array
    {
        $party = is_array($payload['party'] ?? null) ? $payload['party'] : [];

        $vatCountry = CountryResolver::fromVatNumber(is_string($party['vat_number'] ?? null) ? $party['vat_number'] : null);
        $ibanCountry = CountryResolver::fromIban(is_string($party['iban'] ?? null) ? $party['iban'] : null);

        if ($vatCountry !== null && $ibanCountry !== null && $vatCountry !== $ibanCountry) {
            return [new Finding(
                code: 'geography.country_mismatch',
                severity: Severity::Warning,
                path: 'party',
                message: "Het btw-nummer hoort bij {$vatCountry}, het rekeningnummer bij {$ibanCountry}. Controleer of beide van dezelfde partij zijn.",
                current: ['vat_country' => $vatCountry, 'iban_country' => $ibanCountry],
                suggestion: null,
            )];
        }

        return [];
    }
}
