<?php

declare(strict_types=1);

namespace App\Accounting\Validation\Validators;

use App\Accounting\Validation\Contracts\DocumentValidator;
use App\Accounting\Validation\Finding;
use App\Accounting\Validation\Severity;

/**
 * Detecteert vreemde valuta: alles anders dan de basisvaluta (EUR) wordt als info
 * geflagd zodat de consumer een koers/valuta-keuze kan tonen vóór het boeken.
 */
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
            blocking: false, // elke 3-letter code passeert `currency` bij het boeken
            path: 'currency',
            message: "Deze factuur staat in {$normalized}, niet in euro's. Controleer de valuta en de gehanteerde koers vóór het boeken.",
            current: $currency,
            suggestion: null,
        )];
    }
}
