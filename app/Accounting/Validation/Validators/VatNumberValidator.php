<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Validators;

use App\Accounting\Validation\Contracts\DocumentValidator;
use App\Accounting\Validation\Finding;
use App\Accounting\Validation\Geography\CountryResolver;
use App\Accounting\Validation\Geography\Region;
use App\Accounting\Validation\Severity;
use App\Rules\ValidVatNumber;

final class VatNumberValidator implements DocumentValidator
{
    /** @var array<string, string> Per-land regex (op het genormaliseerde nummer). */
    private const PATTERNS = [
        'NL' => '/^NL\d{9}B\d{2}$/',
        'DE' => '/^DE\d{9}$/',
        'BE' => '/^BE0\d{9}$/',
        'FR' => '/^FR[A-Z0-9]{2}\d{9}$/',
        'IT' => '/^IT\d{11}$/',
        'ES' => '/^ES[A-Z0-9]\d{7}[A-Z0-9]$/',
    ];

    private const GENERIC = '/^[A-Z]{2}[A-Z0-9]{2,12}$/';

    public function validate(array $payload): array
    {
        $party = is_array($payload['party'] ?? null) ? $payload['party'] : [];
        $raw = $party['vat_number'] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
        $country = CountryResolver::fromVatNumber($normalized);

        if ($country === null || CountryResolver::region($country) === Region::NonEu) {
            return [];
        }

        if (! $this->matchesFormat($country, $normalized)) {
            return [new Finding(
                code: 'vat_number.malformed',
                severity: $country === 'NL' ? Severity::Error : Severity::Warning,
                blocking: $country === 'NL',
                path: 'party.vat_number',
                message: "Het btw-nummer heeft niet de vorm van een {$country}-btw-nummer. Controleer het nummer op de factuur.",
                current: $raw,
                suggestion: null,
            )];
        }

        if ($country === 'NL' && ! ValidVatNumber::passesNlChecksum($normalized)) {
            $name = is_string($party['name'] ?? null) && trim($party['name']) !== '' ? trim($party['name']) : null;
            $subject = $name !== null ? "Het btw-nummer van '{$name}'" : 'Het btw-nummer';

            return [new Finding(
                code: 'vat_number.checksum',
                severity: Severity::Error,
                blocking: true,
                path: 'party.vat_number',
                message: "{$subject} klopt niet — het bestaat niet in deze vorm. Een Nederlands btw-nummer is NL + 9 cijfers + B + 2 cijfers (bijvoorbeeld NL000099998B57). De boeking wordt hierop geweigerd.",
                current: $raw,
                suggestion: 'Controleer en corrigeer het btw-nummer van de klant.',
            )];
        }

        if ($normalized !== $raw) {
            return [new Finding(
                code: 'vat_number.normalize',
                severity: Severity::Info,
                blocking: false,
                path: 'party.vat_number',
                message: 'Het btw-nummer klopt, maar staat met spaties of leestekens. Wij stellen de nette schrijfwijze voor.',
                current: $raw,
                suggestion: $normalized,
            )];
        }

        return [];
    }

    private function matchesFormat(string $country, string $normalized): bool
    {
        $pattern = self::PATTERNS[$country] ?? self::GENERIC;

        return preg_match($pattern, $normalized) === 1;
    }
}
