<?php

declare(strict_types=1);

namespace App\Integrations\Exact;

final class ExactUserId
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($value, " \t\n\r\0\x0B{}"));

        return $normalized === '' ? null : $normalized;
    }

    /** @return array<int, string> */
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
