<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Validators;

use App\Accounting\Validation\Contracts\DocumentValidator;
use App\Accounting\Validation\Finding;
use App\Accounting\Validation\Geography\CountryResolver;
use App\Accounting\Validation\Geography\Region;
use App\Accounting\Validation\Severity;
use App\Accounting\Validation\Support\Money;

/**
 * De kern-boekhoudregel: de juiste BTW-behandeling volgt uit geografie + BTW-identiteit.
 * Intra-EU B2B met een EU-BTW-nummer hoort verlegd te zijn (0% + "BTW verlegd"); een
 * niet-EU leverancier die een binnenlands tarief rekent is fout (import-BTW loopt via de
 * douane, niet op de factuur). Binnenland en onbepaald → geen oordeel.
 */
final class VatTreatmentValidator implements DocumentValidator
{
    public function validate(array $payload): array
    {
        $party = is_array($payload['party'] ?? null) ? $payload['party'] : [];
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];

        $vatNumber = is_string($party['vat_number'] ?? null) ? $party['vat_number'] : null;
        $iban = is_string($party['iban'] ?? null) ? $party['iban'] : null;

        $country = CountryResolver::fromVatNumber($vatNumber) ?? CountryResolver::fromIban($iban);
        $region = CountryResolver::region($country);

        if ($region === Region::Domestic || $region === Region::Unknown) {
            return [];
        }

        $findings = [];

        foreach (array_values($lines) as $index => $line) {
            $rate = is_array($line) ? Money::toFloat($line['tax_rate'] ?? null) : null;

            if ($rate === null || $rate <= 0.0) {
                continue;
            }

            if ($region === Region::Eu && $vatNumber !== null) {
                $findings[] = new Finding(
                    code: 'vat_treatment.reverse_charge_expected',
                    severity: Severity::Warning,
                    path: "lines.{$index}.tax_rate",
                    message: 'Intra-EU B2B-leverancier met BTW-nummer: BTW verlegd verwacht (0%).',
                    current: $rate,
                    suggestion: 'reverse_charge',
                );

                continue;
            }

            if ($region === Region::NonEu) {
                $findings[] = new Finding(
                    code: 'vat_treatment.domestic_rate_on_non_eu',
                    severity: Severity::Error,
                    path: "lines.{$index}.tax_rate",
                    message: 'Niet-EU leverancier met een binnenlands BTW-tarief; import-BTW loopt via de douane (0% op de factuur).',
                    current: $rate,
                    suggestion: 0,
                );
            }
        }

        return $findings;
    }
}
