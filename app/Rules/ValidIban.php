<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidIban implements ValidationRule
{
    /** @var array<string, int> IBAN-lengte per land (gangbare EU + UK/CH). */
    private const LENGTHS = [
        'NL' => 18, 'DE' => 22, 'BE' => 16, 'FR' => 27, 'ES' => 24, 'IT' => 27,
        'AT' => 20, 'PT' => 25, 'IE' => 22, 'FI' => 18, 'LU' => 20, 'PL' => 28,
        'SE' => 24, 'DK' => 18, 'NO' => 15, 'CH' => 21, 'GB' => 22,
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        if (! self::isValid(self::normalize($value))) {
            $fail('Het rekeningnummer (IBAN) is ongeldig. Controleer het nummer op de factuur.');
        }
    }

    public static function normalize(string $raw): string
    {
        return strtoupper(preg_replace('/\s+/', '', $raw) ?? '');
    }

    public static function isValid(string $normalized): bool
    {
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $normalized) !== 1) {
            return false;
        }

        $country = substr($normalized, 0, 2);

        if (isset(self::LENGTHS[$country]) && strlen($normalized) !== self::LENGTHS[$country]) {
            return false;
        }

        return self::mod97($normalized) === 1;
    }

    private static function mod97(string $iban): int
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
