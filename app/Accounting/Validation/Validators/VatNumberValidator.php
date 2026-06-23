<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Validators;

use App\Accounting\Validation\Contracts\DocumentValidator;
use App\Accounting\Validation\Finding;
use App\Accounting\Validation\Geography\CountryResolver;
use App\Accounting\Validation\Geography\Region;
use App\Accounting\Validation\Severity;

/**
 * Controleert het formaat van een EU-BTW-nummer (geen live VIES — dat is een aparte,
 * latere enrichment). NL wordt strikt gevalideerd (`NL` + 9 cijfers + `B` + 2);
 * overige EU-landen per patroon, met een generieke fallback. Niet-EU prefixes worden
 * overgeslagen (geen betrouwbaar formaat te bepalen).
 */
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

    /** Generiek: EU-prefix + 2–12 alfanumerieke tekens. */
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

        // Niet-EU (of geen) prefix → geen betrouwbaar formaatoordeel.
        if ($country === null || CountryResolver::region($country) === Region::NonEu) {
            return [];
        }

        if (! $this->matchesFormat($country, $normalized)) {
            // NL valideert Exact strikt op formaat → een misvormd NL-nummer faalt de boeking
            // deterministisch en is dus een harde fout. Overige EU-formaten houden we advisory
            // (Exact's gedrag per land minder zeker).
            return [new Finding(
                code: 'vat_number.malformed',
                severity: $country === 'NL' ? Severity::Error : Severity::Warning,
                path: 'party.vat_number',
                message: "BTW-nummer heeft geen geldig {$country}-formaat.",
                current: $raw,
                suggestion: null,
            )];
        }

        // NL-controlecijfer (11-proef): Exact weigert nummers die hier doorheen vallen
        // (HTTP 500 "Ongeldig controlecijfer voor btw-nummer") — een boeking met zo'n nummer
        // kan nooit slagen. Error, niet warning: de dry-run moet Exact's harde weigering
        // spiegelen zodat de consument het vóór de boeking ziet i.p.v. via een 422.
        if ($country === 'NL' && ! self::passesDutchVatChecksum($normalized)) {
            $name = is_string($party['name'] ?? null) && trim($party['name']) !== '' ? trim($party['name']) : null;
            $subject = $name !== null ? "Het btw-nummer van '{$name}'" : 'Het btw-nummer';

            return [new Finding(
                code: 'vat_number.checksum',
                severity: Severity::Error,
                path: 'party.vat_number',
                message: "{$subject} is ongeldig (controlecijfer klopt niet). Een Nederlands btw-nummer heeft de vorm NL + 9 cijfers + B + 2 cijfers (bijvoorbeeld NL123456789B01).",
                current: $raw,
                suggestion: 'Controleer en corrigeer het btw-nummer van de klant.',
            )];
        }

        if ($normalized !== $raw) {
            return [new Finding(
                code: 'vat_number.normalize',
                severity: Severity::Info,
                path: 'party.vat_number',
                message: 'BTW-nummer is geldig maar niet genormaliseerd.',
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

    /**
     * Nederlandse 11-proef over de 9 cijfers tussen `NL` en `B`: de som van cijfer × gewicht
     * (9 t/m 1) moet deelbaar zijn door 11. Aanname: het nummer matcht al `NL\d{9}B\d{2}`.
     */
    private static function passesDutchVatChecksum(string $normalized): bool
    {
        $digits = substr($normalized, 2, 9);

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (9 - $i) * (int) $digits[$i];
        }

        return $sum % 11 === 0;
    }
}
