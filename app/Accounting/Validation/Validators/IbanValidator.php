<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Validators;

use App\Accounting\Validation\Contracts\DocumentValidator;
use App\Accounting\Validation\Finding;
use App\Accounting\Validation\Severity;

/**
 * Valideert het IBAN van de party via mod-97 (ISO 13616) + lengte-per-land. Stelt het
 * genormaliseerde IBAN voor (spaties weg, uppercase); bij een gefaalde checksum geen gok.
 */
final class IbanValidator implements DocumentValidator
{
    /** @var array<string, int> IBAN-lengte per land (gangbare EU + UK/CH). */
    private const LENGTHS = [
        'NL' => 18, 'DE' => 22, 'BE' => 16, 'FR' => 27, 'ES' => 24, 'IT' => 27,
        'AT' => 20, 'PT' => 25, 'IE' => 22, 'FI' => 18, 'LU' => 20, 'PL' => 28,
        'SE' => 24, 'DK' => 18, 'NO' => 15, 'CH' => 21, 'GB' => 22,
    ];

    public function validate(array $payload): array
    {
        $party = is_array($payload['party'] ?? null) ? $payload['party'] : [];
        $raw = $party['iban'] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $normalized = strtoupper(preg_replace('/\s+/', '', $raw) ?? '');

        if (! $this->isValid($normalized)) {
            return [new Finding(
                code: 'iban.checksum_invalid',
                severity: Severity::Error,
                path: 'party.iban',
                message: 'Het rekeningnummer (IBAN) is ongeldig. Controleer het nummer op de factuur.',
                current: $raw,
                suggestion: null,
            )];
        }

        if ($normalized !== $raw) {
            return [new Finding(
                code: 'iban.normalize',
                severity: Severity::Info,
                path: 'party.iban',
                message: 'Het rekeningnummer klopt, maar staat met spaties of kleine letters. Wij stellen de nette schrijfwijze voor.',
                current: $raw,
                suggestion: $normalized,
            )];
        }

        return [];
    }

    private function isValid(string $iban): bool
    {
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $iban)) {
            return false;
        }

        $country = substr($iban, 0, 2);

        if (isset(self::LENGTHS[$country]) && strlen($iban) !== self::LENGTHS[$country]) {
            return false;
        }

        return $this->mod97($iban) === 1;
    }

    /**
     * ISO 7064 mod-97-10: verplaats de eerste 4 tekens naar achteren, letters → cijfers
     * (A=10..Z=35), en reken modulo 97 in stukken (geen bigint nodig).
     */
    private function mod97(string $iban): int
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);

        $digits = '';
        foreach (str_split($rearranged) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) (($remainder.$chunk) % 97);
        }

        return $remainder;
    }
}
