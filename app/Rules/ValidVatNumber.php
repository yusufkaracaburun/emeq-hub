<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valideert een EU-btw-nummer **offline** (geen VIES-call): generiek EU-formaat voor alle landen,
 * plus een strikt controlecijfer voor NL. Het NL-controlecijfer accepteert twee schema's — de moderne
 * btw-id (mod-97) óf de legacy elfproef — zodat zowel rechtspersonen als ZZP'ers (sinds 2020) door de
 * check komen. Spiegelt Exact's `controlecijfer`-weigering aan de Hub-rand i.p.v. via een upstream-500.
 *
 * Bron-algoritme: yolk/valvat (checksum/nl.rb) — `mod97(NL…B..) == 1 || legacy-elfproef`. Niet-NL EU
 * blijft formaat-only (Exact's gedrag per ander land is onzeker → niet hard blokkeren).
 */
final class ValidVatNumber implements ValidationRule
{
    private const NL_FORMAT = '/^NL\d{9}B\d{2}$/';

    private const EU_FORMAT = '/^[A-Z]{2}[A-Z0-9]{2,13}$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return; // leegte regelt `nullable`/`required` apart
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

    /** Niet-alfanumeriek eruit, hoofdletters: "nl 1234 56789 b01" → "NL123456789B01". */
    public static function normalize(string $raw): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
    }

    /** Geldig NL-nummer: juist formaat én een kloppend controlecijfer. */
    public static function isValidNl(string $normalized): bool
    {
        return preg_match(self::NL_FORMAT, $normalized) === 1
            && self::passesNlChecksum($normalized);
    }

    /**
     * NL-controlecijfer — geldig als ÉÉN van beide klopt (aanname: matcht al `NL\d{9}B\d{2}`):
     *  - modern btw-id (2020+): mod-97 over `NL…B..` met letters→cijfers (A=10..Z=35), rest == 1.
     *  - legacy elfproef: 9 cijfers, gewichten 9..2 plus −1 op het 9e, som > 0 en deelbaar door 11.
     */
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

        // Iteratieve mod-97 (geen bcmath nodig voor het ~17-cijferige getal).
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
