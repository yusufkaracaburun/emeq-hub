<?php

declare(strict_types=1);

namespace App\Support\Exact;

/**
 * Exact levert het UserID als GUID, maar niet altijd in dezelfde schrijfwijze:
 * .NET-formatters geven "D" (kaal) of "B" (met accolades), in klein- of
 * hoofdletters. Zonder normalisatie mislukt de match in /exact/stop stil en
 * krijgt de gebruiker de zachte pagina terwijl er wél een koppeling is.
 */
final class ExactUserId
{
    /**
     * Kale, kleingeletterde vorm. Null bij ontbrekende of lege invoer.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($value, " \t\n\r\0\x0B{}"));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * De schrijfwijzen waarin een genormaliseerd UserID opgeslagen kan zijn.
     * Nodig omdat koppelingen van vóór deze normalisatie de rauwe waarde uit
     * /Me bevatten, en een JSON-kolom zich niet case-insensitive laat matchen
     * zonder database-specifieke SQL.
     *
     * @return array<int, string>
     */
    public static function storageCandidates(string $normalized): array
    {
        $upper = mb_strtoupper($normalized);

        return [
            $normalized,
            $upper,
            '{'.$normalized.'}',
            '{'.$upper.'}',
        ];
    }
}
