<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Validators;

use App\Accounting\Validation\Contracts\DocumentValidator;
use App\Accounting\Validation\Finding;
use App\Accounting\Validation\Severity;

final class CurrencyValidator implements DocumentValidator
{
    private const BASE = 'EUR';

    public function validate(array $payload): array
    {
        $currency = $payload['currency'] ?? null;

        if (! is_string($currency) || $currency === '') {
            return [];
        }

        $normalized = strtoupper(trim($currency));

        if ($normalized === self::BASE) {
            return [];
        }

        return [new Finding(
            code: 'currency.foreign',
            severity: Severity::Info,
            blocking: false,
            path: 'currency',
            message: "Deze factuur staat in {$normalized}, niet in euro's. Controleer de valuta en de gehanteerde koers vóór het boeken.",
            current: $currency,
            suggestion: null,
        )];
    }
}
