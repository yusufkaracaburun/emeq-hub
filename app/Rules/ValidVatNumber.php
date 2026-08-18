<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidVatNumber implements ValidationRule
{
    private const NL_FORMAT = '/^NL\d{9}B\d{2}$/';

    private const EU_FORMAT = '/^[A-Z]{2}[A-Z0-9]{2,13}$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $normalized = self::normalize($value);

        if (preg_match(self::EU_FORMAT, $normalized) !== 1) {
            $fail('Het btw-nummer heeft geen geldig Europees formaat.');

            return;
        }

        if (str_starts_with($normalized, 'NL') && ! self::isValidNl($normalized)) {
            $fail('Het btw-nummer is ongeldig (controlecijfer klopt niet). Een Nederlands btw-nummer heeft de vorm NL + 9 cijfers + B + 2 cijfers (bijvoorbeeld NL000099998B57).');
        }
    }

    public static function normalize(string $raw): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
    }

    public static function isValidNl(string $normalized): bool
    {
        return preg_match(self::NL_FORMAT, $normalized) === 1
            && self::passesNlChecksum($normalized);
    }

    public static function passesNlChecksum(string $normalized): bool
    {
        return self::passesMod97($normalized) || self::passesEleven($normalized);
    }

    private static function passesMod97(string $normalized): bool
    {
        $expanded = '';
        foreach (str_split($normalized) as $char) {
            $expanded .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($expanded) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder === 1;
    }

    private static function passesEleven(string $normalized): bool
    {
        $digits = substr($normalized, 2, 9);

        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += (9 - $i) * (int) $digits[$i];
        }
        $sum -= (int) $digits[8];

        return $sum > 0 && $sum % 11 === 0;
    }
}
