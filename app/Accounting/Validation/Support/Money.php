<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Support;

/**
 * Numerieke helpers voor de validators. Leest waarden defensief uit ongesaneerde
 * draft-input en vergelijkt met een centtolerantie (OCR levert floats).
 */
final class Money
{
    /**
     * Coerce naar float, of null als de waarde niet numeriek is (OCR-rotzooi).
     */
    public static function toFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    public static function close(float $a, float $b, float $epsilon = 0.01): bool
    {
        return abs($a - $b) <= $epsilon;
    }
}
